<?php

namespace App\Http\Controllers\IdPasses;

use App\Http\Controllers\Controller;
use App\Http\Controllers\IdPasses\Concerns\Guards;
use App\Models\Contact;
use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use App\Models\Staff;
use App\Services\IdPass\CardService;
use App\Services\IdPass\IdPassKinds;
use App\Services\IdPass\IdPassNotifier;
use App\Services\IdPass\PhotoBackground;
use App\Services\IdPass\PhotoService;
use App\Services\IdPass\RecordSync;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Issuing many Staff IDs or Compound Worker IDs (Aluminium, Carpenter and the other categories of contacts) in one go. The people are chosen from a list, each is checked first
 * (full name, contact, photograph), the numbers can be given person by person, and the work is done in small lots of rows (one request each) so WIMS stays quick and the screen shows the real progress.
 * Every card gets its own "issued" line in its history, with the person who made it, the date and time, and the reference of the run.
 */
class BulkController extends Controller
{
    use Guards;

    private const PAGE = 40;

    private const ALL = 300;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function kindForBulk(Request $request): string
    {
        $kind = $this->kindOf($request);
        abort_unless(in_array($kind, ['staff', 'compound'], true), 404);
        $this->must($kind, 'create');

        return $kind;
    }

    public function index(Request $request)
    {
        $kind = $this->kindForBulk($request);
        $k = IdPassKinds::get($kind);
        $cats = IdPassCategory::query()->ofKind($kind)->active()->where('pooled', false)->orderBy('sort_order')->orderBy('name')->get();

        return Inertia::render('IdPasses/Cards/Bulk', [
            'info' => ['kind' => $kind, 'label' => __($k['label']), 'singular' => __($k['singular']), 'icon' => $k['icon'], 'tone' => $k['tone'], 'route' => $k['route']],
            'categories' => $cats->map(function (IdPassCategory $c) {
                $next = CardService::suggest($c);

                return ['id' => $c->id, 'name' => $c->name, 'next_seq' => $next['seq'], 'next' => $next['number'], 'prefix' => $c->prefix, 'digits' => $c->digits, 'validity_value' => $c->validity_value, 'validity_unit' => $c->validity_unit,
                    'requires_approval' => $c->requires_approval, 'photo_required' => $c->photo_required, 'band' => $c->band_color, 'photo_bg' => $c->photo_background, 'contact_categories' => $c->contact_categories ?: []];
            })->values(),
            'today' => Carbon::today(config('app.timezone'))->toDateString(), 'canNumber' => $request->user()->can('manage-id-numbering'), 'minPhotoWidth' => PhotoService::MIN_WIDTH, 'chunk' => 6,
        ]);
    }

    /** The people to choose from, with what each of them still lacks for an ID. A person who already holds one in use is shown but cannot be chosen. */
    public function people(Request $request)
    {
        $kind = $this->kindForBulk($request);
        $d = $request->validate(['category_id' => 'required|integer', 'q' => 'nullable|string|max:80', 'page' => 'nullable|integer|min:1', 'all' => 'nullable|boolean']);
        $cat = IdPassCategory::query()->active()->ofKind($kind)->findOrFail((int) $d['category_id']);
        $q = trim((string) ($d['q'] ?? ''));
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $all = $request->boolean('all');
        if ($kind === 'staff') {
            $base = Staff::query()->whereIn('status', ['Active', 'Retired Active'])->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('staff_number', 'like', $like)->orWhere('position', 'like', $like)))->orderBy('name');
            $col = 'staff_id';
        } else {
            $allowed = array_map('mb_strtolower', (array) ($cat->contact_categories ?: []));
            $base = Contact::query()->when($allowed, fn ($w) => $w->whereIn(DB::raw('LOWER(category)'), $allowed))->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('phone', 'like', $like)))->orderBy('name');
            $col = 'contact_id';
        }
        $total = (clone $base)->count();
        $page = max(1, (int) ($d['page'] ?? 1));
        $people = $all ? (clone $base)->limit(self::ALL)->get() : (clone $base)->forPage($page, self::PAGE)->get();
        $open = IdPassCard::query()->where('kind', $kind)->whereIn('status', IdPassCard::OPEN)->whereIn($col, $people->pluck('id'))->pluck('number', $col);
        $rows = $people->map(function ($p) use ($kind, $open) {
            $phone = $kind === 'staff' ? $p->mobile_no : $p->phone;
            $row = ['id' => $p->id, 'name' => trim((string) $p->name), 'sub' => $kind === 'staff' ? trim($p->staff_number . ' · ' . $p->position, ' ·') : (string) $p->category, 'phone' => $phone ? trim((string) $phone) : '',
                'has_photo' => $kind === 'staff' ? (bool) $p->photo : false, 'gender' => $kind === 'staff' ? PhotoService::gender($p->gender) : 'male', 'card' => $open[$p->id] ?? null];
            if ($kind === 'staff') {
                $row['title'] = $p->position;
            }

            return $row;
        })->values();

        return response()->json(['rows' => $rows, 'total' => $total, 'page' => $page, 'last' => (int) max(1, ceil($total / self::PAGE)), 'capped' => $all && $total > self::ALL]);
    }

    /** One lot of rows. Each row is checked again here (the page's check is only a help), made, and answered on its own: one failing row never stops the others. */
    public function issue(Request $request)
    {
        $kind = $this->kindForBulk($request);
        $d = $request->validate(['category_id' => 'required|integer', 'issued_at' => 'required|date', 'expires_at' => 'nullable|date', 'ref' => 'required|string|max:40', 'rows' => 'required|array|min:1|max:12',
            'rows.*.id' => 'required|integer', 'rows.*.seq' => 'nullable|integer|min:1|max:99999999', 'rows.*.phone' => 'nullable|string|max:60', 'rows.*.title' => 'nullable|string|max:120', 'rows.*.avatar' => 'nullable|in:male,female',
            'rows.*.photo' => 'nullable|file|image|max:' . PhotoService::MAX_KB, 'rows.*.photo_original' => 'nullable|file|image|max:' . PhotoService::MAX_KB, 'rows.*.photo_cutout' => 'nullable|file|mimes:png|max:' . PhotoService::MAX_KB]);
        $cat = IdPassCategory::query()->active()->ofKind($kind)->findOrFail((int) $d['category_id']);
        abort_if($cat->pooled, 422);
        $by = $request->user();
        $ids = collect($d['rows'])->pluck('id')->map(fn ($i) => (int) $i)->all();
        $people = ($kind === 'staff' ? Staff::query() : Contact::query())->whereIn('id', $ids)->get()->keyBy('id');
        $results = [];
        $made = [];
        $wasMuted = IdPassNotifier::$muted;
        IdPassNotifier::$muted = true;      // a run of many IDs is one entry in the activity log, not a message for each card
        try {
            foreach ($d['rows'] as $i => $r) {
                $id = (int) $r['id'];
                $results[] = $this->one($request, $kind, $cat, $i, $r, $people[$id] ?? null, $d, $by, $made);
            }
        } finally {
            IdPassNotifier::$muted = $wasMuted;
        }
        if ($made) {
            log_activity('RANS IDs issued in bulk', ['category' => $cat->name, 'reference' => $d['ref'], 'count' => count($made), 'from' => $made[0]->number, 'to' => end($made)->number], $made[0], 'RANS ID & Pass Management');
            if ($cat->requires_approval) {
                IdPassNotifier::fire('approval_needed', $made[0], $by);
            }
        }

        return response()->json(['results' => $results]);
    }

    private function one(Request $request, string $kind, IdPassCategory $cat, int $i, array $r, $person, array $d, $by, array &$made): array
    {
        $id = (int) $r['id'];
        $photo = null;
        $fail = fn (string $m) => ['id' => $id, 'ok' => false, 'message' => $m];
        try {
            if (! $person) {
                return $fail(__('This person was not found.'));
            }
            $name = trim((string) $person->name);
            $phone = trim((string) ($r['phone'] ?? '')) ?: trim((string) ($kind === 'staff' ? $person->mobile_no : $person->phone));
            $missing = [];
            if ($name === '') {
                $missing[] = __('full name');
            }
            if ($phone === '') {
                $missing[] = __('contact');
            }
            $file = $request->file("rows.$i.photo");
            $avatar = ! $file && ! empty($r['avatar']) && ($kind === 'compound' || ! $person->photo) ? $r['avatar'] : null;     // an avatar stands in only where there is no photograph
            if ($kind === 'staff' && ! $file && ! $person->photo && ! $avatar) {
                $missing[] = __('photograph');
            }
            if ($kind === 'compound' && ! $file && ! $avatar) {
                $missing[] = __('photograph');
            }
            if ($missing) {
                return $fail(__('Missing: :m.', ['m' => implode(', ', $missing)]));
            }
            $seq = ! empty($r['seq']) ? (int) $r['seq'] : null;
            if ($seq && ! $by->can('manage-id-numbering')) {
                return $fail(__('Only people who may manage numbering can choose an ID number.'));
            }
            if ($avatar) {
                $photo = PhotoService::avatar($avatar, $cat->photo_background);
            } elseif ($file) {
                $photo = PhotoService::store($file, $request->file("rows.$i.photo_original"), $request->file("rows.$i.photo_cutout"), $cat->photo_background);
            } else {
                $photo = PhotoService::fromStaff($person, $cat->photo_background);
                if (! $photo) {
                    return $fail(__('Missing: :m.', ['m' => __('photograph (the file is not there)')]));
                }
            }
            $card = CardService::issue($cat, ['staff_id' => $kind === 'staff' ? $id : null, 'contact_id' => $kind === 'compound' ? $id : null, 'phone' => $phone, 'title' => $r['title'] ?? null,
                'issued_at' => $d['issued_at'], 'expires_at' => $d['expires_at'] ?? null, '_bulk' => $d['ref']], $photo, $by, $seq, true);
            $made[] = $card;

            return ['id' => $id, 'ok' => true, 'number' => $card->number, 'card_id' => $card->id, 'seq' => (int) $card->seq_no, 'name' => $card->full_name, 'status' => $card->status, 'bg_pending' => (bool) ($cat->photo_background && empty($photo['bg']))];
        } catch (ValidationException $e) {
            $this->discard($photo);

            return $fail(collect($e->errors())->flatten()->first() ?: __('This ID could not be made.'));
        } catch (\Throwable $e) {
            $this->discard($photo);
            \Log::warning('Bulk ID row failed', ['person' => $id, 'error' => $e->getMessage()]);

            return $fail(__('This ID could not be made.'));
        }
    }

    private function discard(?array $photo): void
    {
        if ($photo) {
            foreach (['path', 'original', 'cutout'] as $k) {
                PhotoService::delete($photo[$k] ?? null);
            }
        }
    }

    /** After a run: the photographs of the new cards are put on the colour of the category. What can be done on the server is done; the rest is listed for the browser (the cut-out of a person is made there). */
    public function backgrounds(Request $request)
    {
        $kind = $this->kindForBulk($request);
        $d = $request->validate(['ids' => 'required|array|min:1|max:' . self::ALL, 'ids.*' => 'integer']);
        $out = ['recoloured' => 0, 'kept' => 0, 'no_photo' => 0, 'pending' => []];
        $cards = IdPassCard::query()->with('category')->where('kind', $kind)->where('created_by_user_id', $request->user()->id)->whereIn('id', array_map('intval', $d['ids']))->whereIn('status', IdPassCard::OPEN)->get();
        foreach ($cards as $card) {
            $hex = optional($card->category)->photo_background;
            if (! PhotoBackground::valid($hex)) {
                continue;
            }
            $r = PhotoBackground::apply($card, $hex);
            if ($r === 'needs_browser') {
                $route = IdPassKinds::get($card->kind)['route'];
                $out['pending'][] = ['id' => $card->id, 'number' => $card->number, 'name' => $card->full_name, 'photo' => route('id-passes.' . $route . '.photo-file', [$card->id]), 'save' => route('id-passes.' . $route . '.photo', [$card->id])];
            } else {
                $out[$r]++;
            }
        }

        return response()->json($out);
    }

    /** After a run: the people who had nothing in their record get what was added on this page (phone, photograph, position): only empty fields are filled, nothing is replaced. */
    public function sync(Request $request)
    {
        $kind = $this->kindForBulk($request);
        $d = $request->validate(['ids' => 'required|array|min:1|max:' . self::ALL, 'ids.*' => 'integer']);
        $cards = IdPassCard::query()->where('kind', $kind)->where('created_by_user_id', $request->user()->id)->whereIn('id', array_map('intval', $d['ids']))->get();
        $r = RecordSync::fillGaps($cards, $request->user());
        $parts = array_filter([$r['phones'] ? __(':n contact numbers', ['n' => $r['phones']]) : null, $r['photos'] ? __(':n photographs', ['n' => $r['photos']]) : null, $r['positions'] ? __(':n positions', ['n' => $r['positions']]) : null]);

        return response()->json($r + ['message' => $parts ? __('Saved to the company records: :p, for :n people.', ['p' => implode(', ', $parts), 'n' => $r['people']]) : __('The records already hold everything.')]);
    }
}
