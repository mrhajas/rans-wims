<?php

namespace App\Http\Controllers\IdPasses;

use App\Http\Controllers\Controller;
use App\Http\Controllers\IdPasses\Concerns\Guards;
use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use App\Models\Staff;
use App\Services\IdPass\CardAssets;
use App\Services\IdPass\CardRenderer;
use App\Services\IdPass\CardService;
use App\Services\IdPass\IdPassKinds;
use App\Services\IdPass\IdPassQuery;
use App\Services\IdPass\IdPassSettings;
use App\Services\IdPass\PassAssignments;
use App\Models\IdPassAssignment;
use App\Services\IdPass\PhotoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** The cards of one kind (Staff IDs, Compound Worker IDs, Visitor Passes, Gate Passes): the list, issuing, the card page and everything that changes a card. The route group says which kind. */
class CardController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function categoriesOf(string $kind, bool $onlyActive = true)
    {
        return IdPassCategory::query()->ofKind($kind)->when($onlyActive, fn ($q) => $q->active())->orderBy('sort_order')->orderBy('name')->get();
    }

    private function info(string $kind): array
    {
        $k = IdPassKinds::get($kind);

        return ['kind' => $kind, 'label' => __($k['label']), 'singular' => __($k['singular']), 'icon' => $k['icon'], 'tone' => $k['tone'], 'route' => $k['route']];
    }

    public function index(Request $request)
    {
        $kind = $this->kindOf($request);
        $f = IdPassQuery::filters($request->query());
        $sort = array_key_exists((string) $request->query('sort'), IdPassQuery::SORTS) ? (string) $request->query('sort') : 'created';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $q = IdPassCard::query()->with(['category', 'staff:id,staff_number', 'openAssignment'])->where('kind', $kind);
        IdPassQuery::apply($q, $f);
        IdPassQuery::sort($q, $sort, $dir);
        $rows = $q->paginate(20)->withQueryString()->through(fn (IdPassCard $c) => IdPassQuery::row($c));
        $titles = IdPassCard::query()->where('kind', $kind)->whereNotNull('title')->distinct()->orderBy('title')->limit(80)->pluck('title');

        return Inertia::render('IdPasses/Cards/Index', [
            'info' => $this->info($kind), 'rows' => $rows, 'filters' => $f + ['sort' => $sort, 'dir' => $dir], 'counts' => IdPassQuery::counts([$kind]),
            'categories' => $this->categoriesOf($kind, false)->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->values(), 'titles' => $titles,
            'statuses' => collect(IdPassCard::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(),
            'can' => ['create' => $this->can($kind, 'create'), 'print' => $this->can($kind, 'print'), 'download' => $this->can($kind, 'download'), 'batch' => auth()->user()->can('access-id-print-centre')],
        ]);
    }

    public function create(Request $request)
    {
        $kind = $this->kindOf($request);
        $this->must($kind, 'create');
        $cats = $this->categoriesOf($kind);

        return Inertia::render('IdPasses/Cards/Issue', [
            'info' => $this->info($kind), 'categories' => $cats->map(fn (IdPassCategory $c) => $this->categoryForm($c))->values(), 'gateTypes' => IdPassSettings::gateTypes(), 'today' => Carbon::today(config('app.timezone'))->toDateString(),
            'presetStaff' => $kind === 'staff' && $request->query('staff') ? (int) $request->query('staff') : null, 'presetContact' => in_array($kind, ['compound', 'gate'], true) && $request->query('contact') ? (int) $request->query('contact') : null,
            'canNumber' => auth()->user()->can('manage-id-numbering'), 'minPhotoWidth' => PhotoService::MIN_WIDTH, 'stockHolder' => IdPassSettings::get('idpass_stock_holder'),
        ]);
    }

    private function categoryForm(IdPassCategory $c): array
    {
        $next = CardService::suggest($c);

        return ['id' => $c->id, 'name' => $c->name, 'label' => $c->label, 'next' => $next['number'], 'next_seq' => $next['seq'], 'prefix' => $c->prefix, 'digits' => $c->digits, 'validity_value' => $c->validity_value, 'validity_unit' => $c->validity_unit,
            'pooled' => $c->pooled, 'requires_approval' => $c->requires_approval, 'photo_required' => $c->photo_required, 'vehicle_required' => $c->vehicle_required, 'contact_categories' => $c->contact_categories ?: [], 'band' => $c->band_color, 'arrow' => $c->arrow_color, 'icon' => $c->icon, 'photo_bg' => $c->photo_background];
    }

    private function rules(string $kind): array
    {
        return [
            'category_id' => 'required|integer', 'staff_id' => 'nullable|integer', 'visitor_id' => 'nullable|integer', 'full_name' => 'nullable|string|max:190', 'title' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:60', 'company' => 'nullable|string|max:150', 'issued_at' => 'required|date', 'expires_at' => 'nullable|date', 'host_name' => 'nullable|string|max:150', 'purpose' => 'nullable|string|max:255',
            'authorisation_type' => 'nullable|string|max:80', 'vehicle_registration' => 'nullable|string|max:40', 'vehicle_description' => 'nullable|string|max:150', 'restrictions' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:2000',
            'manual_number' => 'nullable|integer|min:1|max:99999999', 'quantity' => 'nullable|integer|min:1|max:200', 'stock' => 'nullable|boolean', 'contact_id' => 'nullable|integer', 'photo' => 'nullable|file|image|max:' . PhotoService::MAX_KB, 'photo_original' => 'nullable|file|image|max:' . PhotoService::MAX_KB, 'photo_cutout' => 'nullable|file|mimes:png|max:' . PhotoService::MAX_KB, 
        ];
    }

    public function store(Request $request)
    {
        $kind = $this->kindOf($request);
        $this->must($kind, 'create');
        $d = $request->validate($this->rules($kind), ['issued_at.required' => __('Choose the date of issue.'), 'category_id.required' => __('Choose the category.')]);
        $cat = IdPassCategory::query()->active()->ofKind($kind)->findOr((int) $d['category_id'], fn () => throw ValidationException::withMessages(['category_id' => __('Choose a category from the list.')]));
        if ($cat->pooled) {
            $made = CardService::issuePool($cat, (int) ($d['quantity'] ?? 1), $request->user(), $d['expires_at'] ?? null);
            $msg = count($made) === 1 ? __(':n was made and is ready to be given to a visitor.', ['n' => $made[0]->number]) : __(':a passes made: :f to :l. They are ready to be given to visitors.', ['a' => count($made), 'f' => $made[0]->number, 'l' => end($made)->number]);

            return redirect()->route('id-passes.' . IdPassKinds::get($kind)['route'] . '.index')->with('message', $msg);
        }
        if ($kind === 'gate' && $request->boolean('stock')) {
            // Gate Passes made in advance, all in the name of the company; each is given to a person later (Assign)
            $made = CardService::issueStock($cat, (int) ($d['quantity'] ?? 1), $request->user(), $d['expires_at'] ?? null, $d['issued_at'] ?? null);
            $msg = count($made) === 1 ? __(':n was made in the name of :h. Give it to a person when it is needed.', ['n' => $made[0]->number, 'h' => $made[0]->full_name]) : __(':a Gate Passes made: :f to :l, in the name of :h. Give each one to a person when it is needed.', ['a' => count($made), 'f' => $made[0]->number, 'l' => end($made)->number, 'h' => $made[0]->full_name]);

            return redirect()->route('id-passes.' . IdPassKinds::get($kind)['route'] . '.index')->with('message', $msg);
        }
        $manual = ! empty($d['manual_number']) ? (int) $d['manual_number'] : null;
        if ($manual) {
            abort_unless($request->user()->can('manage-id-numbering'), 403, __('Only people who may manage numbering can type an ID number.'));
        }
        $photo = null;
        if ($request->hasFile('photo')) {
            $photo = PhotoService::store($request->file('photo'), $request->file('photo_original'), $request->file('photo_cutout'), $cat->photo_background);
        } elseif ($kind === 'staff' && ! empty($d['staff_id']) && ($s = Staff::query()->find((int) $d['staff_id']))) {
            $photo = PhotoService::fromStaff($s, $cat->photo_background);
        }
        $card = CardService::issue($cat, $d, $photo, $request->user(), $manual);
        $msg = $card->status === IdPassCard::PENDING ? __(':n is waiting for approval.', ['n' => $card->number]) : __(':n was issued to :p.', ['n' => $card->number, 'p' => $card->full_name]);
        if ($photo && ! empty($photo['low'])) {
            $msg .= ' ' . __('The photograph is small, so the print may look soft.');
        }
        if ($photo && $cat->photo_background && empty($photo['bg'])) {
            $msg .= ' ' . __('The photograph background could not be changed to the colour of this ID, so the photograph is as it was taken.');
        }

        return redirect()->route('id-passes.' . IdPassKinds::get($kind)['route'] . '.show', ['card' => $card->id])->with('message', $msg);
    }

    public function show(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind)->load(['staff:id,name,staff_number,position', 'contact:id,name,category,phone', 'visitor:id,full_name,company,phone', 'createdBy:id,name']);
        $u = $request->user();
        $data = CardRenderer::data($card);
        $users = \App\Models\User::withoutGlobalScopes()->whereIn('id', array_filter([$card->created_by_user_id, $card->approved_by_user_id, $card->status_by_user_id]))->pluck('name', 'id');
        $events = $card->events()->with('user:id,name')->limit(100)->get()->map(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'from' => $e->from_status, 'to' => $e->to_status, 'by' => optional($e->user)->name, 'at' => $e->created_at->timezone(config('app.timezone'))->toIso8601String(), 'detail' => $e->detail]);
        $canHistory = $u->can('read-id-print-history');
        $prints = $canHistory ? $card->prints()->with('user:id,name')->limit(100)->get()->map(fn ($p) => ['id' => $p->id, 'action' => $p->action, 'layout' => $p->layout, 'copies' => $p->copies, 'outcome' => $p->outcome, 'by' => optional($p->user)->name,
            'at' => $p->created_at->timezone(config('app.timezone'))->toIso8601String(), 'message' => $p->message]) : [];
        $pending = $card->status === IdPassCard::PENDING;
        $open = in_array($card->status, IdPassCard::OPEN, true);

        return Inertia::render('IdPasses/Cards/Show', [
            'info' => $this->info($kind),
            'card' => IdPassQuery::row($card) + ['status_raw' => $card->status, 'phone' => $card->phone, 'company' => $card->company, 'host' => $card->host_name, 'purpose' => $card->purpose, 'authorisation' => $card->authorisation_type,
                'vehicle_description' => $card->vehicle_description, 'restrictions' => $card->restrictions, 'notes' => $card->notes, 'status_reason' => $card->status_reason, 'decision_reason' => $card->decision_reason,
                'created_by' => optional($card->createdBy)->name, 'approved_by' => $users[$card->approved_by_user_id] ?? null, 'approved_at' => optional($card->approved_at)->toIso8601String(), 'status_by' => $users[$card->status_by_user_id] ?? null,
                'status_at' => optional($card->status_at)->toIso8601String(), 'replaces_id' => $card->replaces_id, 'replaced_by_id' => $card->replaced_by_id, 'replaces_number' => $card->replaces_id ? IdPassCard::query()->whereKey($card->replaces_id)->value('number') : null,
                'replaced_by_number' => $card->replaced_by_id ? IdPassCard::query()->whereKey($card->replaced_by_id)->value('number') : null,
                'staff' => $card->staff ? ['id' => $card->staff->id, 'number' => $card->staff->staff_number, 'url' => route('staffs.show', $card->staff->id)] : null,
                'contact' => $card->contact ? ['id' => $card->contact->id, 'name' => $card->contact->name, 'category' => $card->contact->category] : null, 'visitor' => $card->visitor ? ['id' => $card->visitor->id, 'name' => $card->visitor->full_name] : null,
                'validity_text' => $card->expires_at ? $card->expires_at->locale('en')->translatedFormat('j F Y') : IdPassSettings::get('idpass_no_expiry_text')],
            'front' => CardRenderer::front($data, 'web'), 'back' => CardRenderer::back($data, 'web'), 'css' => CardRenderer::stylesheet('web'),
            'events' => $events, 'prints' => $prints, 'canHistory' => $canHistory,
            'gateTypes' => IdPassSettings::gateTypes(),
            'pooled' => (bool) optional($card->category)->pooled, 'holder' => ($a = PassAssignments::open($card)) ? ['name' => $a->holder_name, 'company' => $a->holder_company, 'phone' => $a->holder_phone, 'host' => $a->host_name, 'visit_id' => $a->visit_id, 'from' => $a->assigned_at->toIso8601String()] : null,
            'assignments' => optional($card->category)->pooled ? PassAssignments::history($card) : [], 'cardHistory' => $this->cardHistory($card),
            'can' => ['give' => optional($card->category)->pooled && $card->status === IdPassCard::ACTIVE && $u->can('assign-visitor-passes'), 'assign' => $kind === 'gate' && $open && ! optional($card->category)->pooled && $this->can($kind, 'update'), 'edit' => $open && $this->can($kind, 'update'), 'approve' => $pending && $this->can($kind, 'approve') && ((int) $card->created_by_user_id !== (int) $u->id || $u->hasRole('Super Admin')),
                'suspend' => $card->status === IdPassCard::ACTIVE && $this->can($kind, 'revoke'), 'reactivate' => $card->status === IdPassCard::SUSPENDED && $this->can($kind, 'revoke'), 'revoke' => $open && $this->can($kind, 'revoke'),
                'renew' => in_array($card->status, [IdPassCard::ACTIVE, IdPassCard::SUSPENDED], true) && $this->can($kind, 'create'), 'replace' => $open && ! $pending && $this->can($kind, 'create'),
                'print' => $this->can($kind, 'print') && ! in_array($card->status, [IdPassCard::REJECTED], true), 'download' => $this->can($kind, 'download') && ! in_array($card->status, [IdPassCard::REJECTED], true), 'number' => $u->can('manage-id-numbering')],
            'layouts' => collect(\App\Models\IdPassPrint::LAYOUTS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'minPhotoWidth' => PhotoService::MIN_WIDTH,
        ]);
    }

    /** The other IDs of the same person (a lost pass replaced, an old pass that ended): from when to when, and why it changed. */
    private function cardHistory(IdPassCard $card): array
    {
        $col = $card->staff_id ? 'staff_id' : ($card->contact_id ? 'contact_id' : ($card->visitor_id && ! optional($card->category)->pooled ? 'visitor_id' : null));
        if (! $col) {
            return [];
        }
        $tz = config('app.timezone');

        return IdPassCard::query()->with('category')->where('kind', $card->kind)->where($col, $card->{$col})->where('id', '<>', $card->id)->orderByDesc('id')->limit(30)->get()->map(fn ($c) => [
            'id' => $c->id, 'number' => $c->number, 'status' => $c->effective_status, 'from' => optional($c->issued_at)->toDateString(), 'to' => in_array($c->status, [IdPassCard::REPLACED, IdPassCard::REVOKED, IdPassCard::REJECTED], true) ? ($c->status_at ? $c->status_at->timezone($tz)->toDateString() : null) : optional($c->expires_at)->toDateString(),
            'reason' => $c->status_reason, 'url' => route('id-passes.' . IdPassKinds::get($c->kind)['route'] . '.show', ['card' => $c->id])])->all();
    }

    /** Gives a stock pass to a visitor by hand (the same hand-over that check-in does) or takes it back. */
    public function give(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        abort_unless($request->user()->can('assign-visitor-passes') && \App\Services\FeatureManager::canAccess('id_visitor', $request->user()), 403);
        $d = $request->validate(['visitor_id' => 'nullable|integer', 'name' => 'nullable|string|max:190', 'company' => 'nullable|string|max:150', 'phone' => 'nullable|string|max:60', 'host' => 'nullable|string|max:150']);
        $v = ! empty($d['visitor_id']) ? \App\Models\Visitor::query()->find((int) $d['visitor_id']) : null;
        $name = trim((string) ($d['name'] ?? '')) ?: optional($v)->full_name;
        if (! $name) {
            throw ValidationException::withMessages(['name' => __('Choose the visitor or type the name.')]);
        }
        PassAssignments::assign($card, ['name' => $name, 'company' => $d['company'] ?? optional($v)->company, 'phone' => $d['phone'] ?? optional($v)->phone, 'host' => $d['host'] ?? null, 'visitor_id' => optional($v)->id], null, $request->user());

        return back()->with('message', __(':n was given to :p.', ['n' => $card->number, 'p' => $name]));
    }

    /** From the Visitor Logs visit page: give a free stock pass to the visitor who is checked in. */
    public function giveToVisit(Request $request, int $visit)
    {
        abort_unless(PassAssignments::enabledFor($request->user()), 403);
        $v = \App\Models\VisitorVisit::query()->with('visitor')->findOrFail($visit);
        abort_unless($v->status === \App\Models\VisitorVisit::IN, 422, __('A pass can only be given to a visitor who is checked in.'));
        abort_if(PassAssignments::forVisit($v->id, $request->user()) && PassAssignments::forVisit($v->id, $request->user())['to'] === null, 422, __('This visitor already has a pass.'));
        $d = $request->validate(['id_pass_id' => 'required|integer'], ['id_pass_id.required' => __('Choose a pass from the list.')]);
        abort_unless(PassAssignments::free(500)->contains('id', (int) $d['id_pass_id']), 422, __('That visitor pass is not free. Choose another one.'));
        $a = PassAssignments::assignToVisit($v, (int) $d['id_pass_id'], $request->user());

        return back()->with('message', __('Pass :n given to :p.', ['n' => optional($a->card)->number, 'p' => $a->holder_name]));
    }

    public function takeBack(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        abort_unless($request->user()->can('assign-visitor-passes') && \App\Services\FeatureManager::canAccess('id_visitor', $request->user()), 403);
        $d = $request->validate(['reason' => 'nullable|string|max:250']);
        $a = PassAssignments::release($card, $request->user(), trim((string) ($d['reason'] ?? '')) ?: __('Returned'));
        abort_unless($a, 422, __('This pass is not with anybody.'));
        if ($a->visit_id) {
            // the visit stays open: the pass is simply no longer held
            \App\Models\VisitorVisit::query()->whereKey($a->visit_id)->update(['idpass_card_id' => null]);
        }

        return back()->with('message', __(':n was taken back.', ['n' => $card->number]));
    }

    /** A lot of a stock (Visitor Passes, or Gate Passes made in advance) made in one small request, so the page can show the real progress of a long run. Answers with the numbers made. */
    public function stock(Request $request)
    {
        $kind = $this->kindOf($request);
        abort_unless(in_array($kind, ['visitor', 'gate'], true), 404);
        $this->must($kind, 'create');
        $d = $request->validate(['category_id' => 'required|integer', 'quantity' => 'required|integer|min:1|max:50', 'issued_at' => 'nullable|date', 'expires_at' => 'nullable|date', 'ref' => 'required|string|max:40']);
        $cat = IdPassCategory::query()->active()->ofKind($kind)->findOr((int) $d['category_id'], fn () => throw ValidationException::withMessages(['category_id' => __('Choose a category from the list.')]));
        $made = $cat->pooled ? CardService::issuePool($cat, (int) $d['quantity'], $request->user(), $d['expires_at'] ?? null, $d['ref'])
            : CardService::issueStock($cat, (int) $d['quantity'], $request->user(), $d['expires_at'] ?? null, $d['issued_at'] ?? null, $d['ref']);

        return response()->json(['made' => collect($made)->map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'status' => $c->status])->values(), 'holder' => $made[0]->full_name]);
    }

    /** Gives a Gate Pass (often one made in advance in the name of the company) to a person: a contact, a visitor of the directory or a name typed. The history keeps who held it before. */
    public function assign(Request $request)
    {
        $kind = $this->kindOf($request);
        abort_unless($kind === 'gate', 404);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'update');
        $d = $request->validate(['contact_id' => 'nullable|integer', 'visitor_id' => 'nullable|integer', 'full_name' => 'nullable|string|max:190', 'phone' => 'nullable|string|max:60', 'company' => 'nullable|string|max:150', 'purpose' => 'required|string|max:255',
            'authorisation_type' => 'nullable|string|max:80', 'vehicle_registration' => 'nullable|string|max:40', 'vehicle_description' => 'nullable|string|max:150'], ['purpose.required' => __('Say why this person may enter (the reason for access).')]);
        if (! empty($d['visitor_id']) && ($v = \App\Models\Visitor::query()->find((int) $d['visitor_id']))) {
            $d['full_name'] = $d['full_name'] ?: $v->full_name;
            $d['phone'] = $d['phone'] ?? $v->phone;
            $d['company'] = $d['company'] ?? $v->company;
        }
        if ($card->category && $card->category->vehicle_required && trim((string) ($d['vehicle_registration'] ?? '')) === '') {
            throw ValidationException::withMessages(['vehicle_registration' => __('The vehicle registration number is required for this category.')]);
        }
        $c = CardService::assign($card, $d, $request->user());

        return back()->with('message', __(':n now belongs to :p.', ['n' => $c->number, 'p' => $c->full_name]));
    }

    public function update(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'update');
        abort_unless(in_array($card->status, IdPassCard::OPEN, true), 422, __('This ID can no longer be changed.'));
        $d = $request->validate(['full_name' => 'nullable|string|max:190', 'title' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:60', 'company' => 'nullable|string|max:150', 'expires_at' => 'nullable|date',
            'host_name' => 'nullable|string|max:150', 'purpose' => 'nullable|string|max:255', 'authorisation_type' => 'nullable|string|max:80', 'vehicle_registration' => 'nullable|string|max:40', 'vehicle_description' => 'nullable|string|max:150',
            'restrictions' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:2000']);
        if ($card->category && $card->category->vehicle_required && trim((string) ($d['vehicle_registration'] ?? $card->vehicle_registration)) === '') {
            throw ValidationException::withMessages(['vehicle_registration' => __('The vehicle registration number is required for this category.')]);
        }
        CardService::update($card, $d, $request->user());

        return back()->with('message', __('Saved.'));
    }

    public function photo(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'update');
        abort_unless(in_array($card->status, IdPassCard::OPEN, true), 422, __('This ID can no longer be changed.'));
        $request->validate(['photo' => 'required|file|image|max:' . PhotoService::MAX_KB, 'photo_original' => 'nullable|file|image|max:' . PhotoService::MAX_KB, 'photo_cutout' => 'nullable|file|mimes:png|max:' . PhotoService::MAX_KB, 'keep_original' => 'nullable|boolean']);
        $p = PhotoService::store($request->file('photo'), $request->file('photo_original'), $request->file('photo_cutout'), $card->category->photo_background);
        if ($request->boolean('keep_original')) {       // the colour was re-done on a photograph already on the card: the picture first taken stays as the original
            PhotoService::delete($p['original']);
            $p['original'] = null;
        }
        CardService::setPhoto($card, $p, $request->user());
        $note = $card->category->photo_background && empty($p['bg']) ? ' ' . __('The background could not be changed to the colour of this ID.') : '';

        return back()->with('message', (! empty($p['low']) ? __('Photograph changed. It is small, so the print may look soft.') : __('Photograph changed.')) . $note);
    }

    /** Both sides of a card as HTML for the cut-out viewer (the staff profile loads them after the page is shown, so the profile itself stays light). */
    public function faces(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $data = CardRenderer::data($card);

        return response()->json(['front' => CardRenderer::front($data, 'web'), 'back' => CardRenderer::back($data, 'web'), 'css' => CardRenderer::stylesheet('web')])->header('Cache-Control', 'private, max-age=60');
    }

    /** The photograph of a card (a small copy with ?w=120 for lists). Private: only people who may read this kind see it. */
    public function photoFile(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $path = CardAssets::photo($card);
        abort_unless($path, 404);
        $w = (int) $request->query('w', 0);
        if ($w > 0 && $w <= 400) {
            $thumb = CardAssets::dir('cache') . '/ph-' . md5($path . filemtime($path) . $w) . '.jpg';
            if (! is_file($thumb)) {
                $src = imagecreatefromjpeg($path);
                $h = (int) round(imagesy($src) * $w / imagesx($src));
                $dst = imagecreatetruecolor($w, $h);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
                imagejpeg($dst, $thumb, 82);
            }
            $path = $thumb;
        }

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400', 'Content-Type' => 'image/jpeg']);
    }

    public function approve(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'approve');
        $d = $request->validate(['remarks' => 'nullable|string|max:500']);
        CardService::approve($card, $request->user(), $d['remarks'] ?? null);

        return back()->with('message', __(':n approved. It can now be printed.', ['n' => $card->number]));
    }

    public function reject(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'approve');
        $d = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => __('Say why it is rejected.')]);
        CardService::reject($card, $request->user(), $d['reason']);

        return back()->with('message', __(':n rejected.', ['n' => $card->number]));
    }

    public function suspend(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'revoke');
        $d = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => __('Say why it is suspended.')]);
        CardService::suspend($card, $request->user(), $d['reason']);

        return back()->with('message', __(':n suspended.', ['n' => $card->number]));
    }

    public function reactivate(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'revoke');
        CardService::reactivate($card, $request->user());

        return back()->with('message', __(':n is active again.', ['n' => $card->number]));
    }

    public function revoke(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'revoke');
        $d = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => __('Say why it is revoked.')]);
        CardService::revoke($card, $request->user(), $d['reason']);

        return back()->with('message', __(':n revoked. It cannot be used again.', ['n' => $card->number]));
    }

    public function renew(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'create');
        $d = $request->validate(['expires_at' => 'nullable|date', 'note' => 'nullable|string|max:300']);
        CardService::renew($card, $request->user(), $d['expires_at'] ?? null, $d['note'] ?? null);

        return back()->with('message', __(':n renewed.', ['n' => $card->number]));
    }

    public function replace(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'create');
        $d = $request->validate(['reason' => 'required|string|max:500', 'photo' => 'nullable|file|image|max:' . PhotoService::MAX_KB, 'photo_original' => 'nullable|file|image|max:' . PhotoService::MAX_KB, 'photo_cutout' => 'nullable|file|mimes:png|max:' . PhotoService::MAX_KB], ['reason.required' => __('Say why it is replaced (lost, damaged, changed).')]);
        $photo = $request->hasFile('photo') ? PhotoService::store($request->file('photo'), $request->file('photo_original'), $request->file('photo_cutout'), $card->category->photo_background) : null;
        $new = CardService::replace($card, $request->user(), $d['reason'], $photo);

        return redirect()->route('id-passes.' . IdPassKinds::get($kind)['route'] . '.show', ['card' => $new->id])->with('message', __(':o was replaced by :n.', ['o' => $card->number, 'n' => $new->number]));
    }
}
