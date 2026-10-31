<?php

namespace App\Http\Controllers\IdPasses;

use App\Http\Controllers\Controller;
use App\Http\Controllers\IdPasses\Concerns\Guards;
use App\Models\Contact;
use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use App\Models\Staff;
use App\Models\Visitor;
use App\Services\IdPass\CardAssets;
use App\Services\IdPass\CardRenderer;
use App\Services\IdPass\CardService;
use App\Services\IdPass\IdPassSettings;
use App\Services\IdPass\NameFitter;
use App\Services\IdPass\PhotoService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** What the issue form asks the server: the people to choose from (staff, contacts, visitors: never copied), the photograph of a staff member, and the preview of the card as it will look. */
class LookupController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function people(Request $request)
    {
        $this->module();
        $d = $request->validate(['source' => 'required|in:staff,contact,visitor', 'q' => 'nullable|string|max:80', 'category_id' => 'nullable|integer']);
        $u = $request->user();
        $q = trim((string) ($d['q'] ?? ''));
        $like = $this->like($q);
        if ($d['source'] === 'staff') {
            abort_unless($u->can('assign-staff-ids'), 403);
            $rows = Staff::query()->whereIn('status', ['Active', 'Retired Active'])->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('staff_number', 'like', $like)->orWhere('position', 'like', $like)))
                ->orderBy('name')->limit(20)->get(['id', 'staff_number', 'name', 'position', 'mobile_no', 'photo', 'gender']);
            $open = IdPassCard::query()->where('kind', 'staff')->whereIn('status', IdPassCard::OPEN)->whereIn('staff_id', $rows->pluck('id'))->pluck('number', 'staff_id');

            return response()->json($rows->map(fn ($s) => ['id' => $s->id, 'name' => NameFitter::display((string) $s->name), 'sub' => trim($s->staff_number . ' · ' . $s->position, ' ·'), 'title' => $s->position, 'phone' => $s->mobile_no, 'gender' => \App\Services\IdPass\PhotoService::gender($s->gender), 'has_photo' => (bool) $s->photo, 'card' => $open[$s->id] ?? null])->values());
        }
        if ($d['source'] === 'contact') {
            abort_unless($u->can('assign-compound-ids') || $u->can('create-people-gate-passes') || $u->can('create-visitor-passes'), 403);
            $cat = ! empty($d['category_id']) ? IdPassCategory::query()->whereIn('kind', ['compound', 'gate'])->find((int) $d['category_id']) : null;
            $allowed = $cat ? array_map('mb_strtolower', (array) ($cat->contact_categories ?: [])) : [];
            $rows = Contact::query()->when($allowed, fn ($w) => $w->whereIn(\DB::raw('LOWER(category)'), $allowed))->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('phone', 'like', $like)))
                ->orderBy('name')->limit(20)->get(['id', 'name', 'category', 'phone']);
            $open = IdPassCard::query()->where('kind', $cat ? $cat->kind : 'compound')->whereIn('status', IdPassCard::OPEN)->whereIn('contact_id', $rows->pluck('id'))->pluck('number', 'contact_id');

            return response()->json($rows->map(fn ($c) => ['id' => $c->id, 'name' => NameFitter::display((string) $c->name), 'sub' => trim($c->category . ' · ' . $c->phone, ' ·'), 'title' => $c->category, 'phone' => $c->phone, 'has_photo' => false, 'card' => $open[$c->id] ?? null])->values());
        }
        abort_unless($u->can('create-visitor-passes') || $u->can('create-people-gate-passes'), 403);
        $rows = Visitor::query()->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('full_name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('company', 'like', $like)))
            ->orderBy('full_name')->limit(20)->get(['id', 'full_name', 'company', 'phone', 'vehicle_registration']);

        return response()->json($rows->map(fn ($v) => ['id' => $v->id, 'name' => NameFitter::display((string) $v->full_name), 'sub' => trim($v->company . ' · ' . $v->phone, ' ·'), 'title' => null, 'phone' => $v->phone, 'company' => $v->company, 'vehicle' => $v->vehicle_registration, 'has_photo' => false, 'card' => null])->values());
    }

    /** The staff photograph (small), for the issue form. */
    public function staffPhoto(Request $request, int $staff)
    {
        $this->module();
        abort_unless($request->user()->can('assign-staff-ids'), 403);
        $s = Staff::query()->findOrFail($staff);
        $p = PhotoService::staffPreview($s);
        abort_unless($p, 404);

        return response()->file($p, ['Cache-Control' => 'private, max-age=600', 'Content-Type' => 'image/jpeg']);
    }

    /** The card as it will be issued (nothing is saved). The cropped photograph comes as a data address. */
    public function preview(Request $request)
    {
        $this->module();
        $d = $request->validate(['category_id' => 'required|integer', 'full_name' => 'nullable|string|max:190', 'title' => 'nullable|string|max:120', 'issued_at' => 'nullable|date', 'expires_at' => 'nullable|date', 'host_name' => 'nullable|string|max:150',
            'vehicle_registration' => 'nullable|string|max:40', 'authorisation_type' => 'nullable|string|max:80', 'staff_id' => 'nullable|integer', 'number' => 'nullable|string|max:40', 'photo_data' => 'nullable|string|max:6000000', 'avatar' => 'nullable|in:male,female']);
        $cat = IdPassCategory::query()->findOrFail((int) $d['category_id']);
        $k = \App\Services\IdPass\IdPassKinds::get($cat->kind);
        abort_unless($request->user()->can($k['create']), 403);
        $photo = ! empty($d['photo_data']) ? PhotoService::previewFile($d['photo_data']) : null;
        if (! $photo && ! empty($d['staff_id']) && $cat->kind === 'staff' && ($s = Staff::query()->find((int) $d['staff_id']))) {
            $photo = PhotoService::staffPreview($s);
        }
        if (! $photo && ! empty($d['avatar'])) {
            $photo = PhotoService::avatarPreview($d['avatar'], $cat->photo_background);
        }
        $issued = ! empty($d['issued_at']) ? Carbon::parse($d['issued_at'], config('app.timezone')) : Carbon::today(config('app.timezone'));
        $data = CardRenderer::draft(['full_name' => $d['full_name'] ?? '', 'title' => $d['title'] ?? null, 'issued' => $issued, 'expires' => ! empty($d['expires_at']) ? Carbon::parse($d['expires_at'], config('app.timezone')) : null, 'host_name' => $d['host_name'] ?? null,
            'vehicle_registration' => $d['vehicle_registration'] ?? null, 'authorisation_type' => $d['authorisation_type'] ?? null], $cat, ($d['number'] ?? '') ?: CardService::suggest($cat)['number'], $photo, 'https://wims.invalid/id-verify/preview');

        return response()->json(['front' => CardRenderer::front($data, 'web'), 'back' => CardRenderer::back($data, 'web')]);
    }

    /** The style sheet of the cards (the fonts are loaded from the public folder), for the preview. */
    public function css()
    {
        $this->module();

        return response(CardRenderer::stylesheet('web'), 200, ['Content-Type' => 'text/css', 'Cache-Control' => 'private, max-age=3600']);
    }
}
