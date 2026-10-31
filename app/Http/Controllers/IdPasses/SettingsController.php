<?php

namespace App\Http\Controllers\IdPasses;

use App\Http\Controllers\Controller;
use App\Http\Controllers\IdPasses\Concerns\Guards;
use App\Models\Contact;
use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use App\Services\IdPass\CardAssets;
use App\Services\IdPass\CardRenderer;
use App\Services\IdPass\IdPassKinds;
use App\Services\IdPass\IdPassSettings;
use App\Services\IdPass\PhotoBackground;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** ID Settings: categories (name, colours, icon, numbering, validity, rules), the shared back of the card, QR verification and expiry notices. What a person may change depends on their permission (settings, templates, numbering, QR). */
class SettingsController extends Controller
{
    use Guards;

    /** Icons of the card pictures that are not meant for a category. */
    private const UTILITY_ICONS = ['user', 'square-check', 'location-dot', 'envelope', 'phone', 'globe', 'inbox'];

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function gate(): void
    {
        $this->module();
        $this->feature('id_settings');
        $this->needAny(['manage-id-settings', 'manage-id-templates', 'manage-id-numbering', 'manage-id-qr-settings']);
    }

    private function icons(): array
    {
        $out = [];
        foreach (glob(resource_path('idpass/img/icons/*.png')) ?: [] as $f) {
            $n = basename($f, '.png');
            if (! in_array($n, self::UTILITY_ICONS, true)) {
                $out[] = $n;
            }
        }
        sort($out);

        return $out;
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = $request->user();
        $used = IdPassCard::query()->selectRaw('category_id, COUNT(*) n, MAX(seq_no) m')->groupBy('category_id')->get()->keyBy('category_id');
        $maxLedger = DB::table('idpass_numbers')->selectRaw('category_id, MAX(seq_no) m')->groupBy('category_id')->pluck('m', 'category_id');
        $cats = IdPassCategory::query()->orderBy('sort_order')->orderBy('name')->get()->map(fn (IdPassCategory $c) => ['id' => $c->id, 'kind' => $c->kind, 'key' => $c->key, 'name' => $c->name, 'label' => $c->label, 'prefix' => $c->prefix, 'digits' => $c->digits, 'next_number' => $c->next_number,
            'band_color' => $c->band_color, 'arrow_color' => $c->arrow_color, 'photo_background' => $c->photo_background, 'icon' => $c->icon, 'contact_categories' => $c->contact_categories ?: [], 'validity_value' => $c->validity_value, 'validity_unit' => $c->validity_unit, 'requires_approval' => $c->requires_approval,
            'photo_required' => $c->photo_required, 'vehicle_required' => $c->vehicle_required, 'pooled' => $c->pooled, 'is_active' => $c->is_active, 'sort_order' => $c->sort_order, 'cards' => (int) ($used[$c->id]->n ?? 0), 'min_next' => (int) ($maxLedger[$c->id] ?? 0) + 1, 'example' => $c->format((int) $c->next_number)]);
        $sample = IdPassCategory::query()->where('kind', 'staff')->first();
        $back = $sample ? CardRenderer::back(CardRenderer::draft(['full_name' => 'Sample', 'issued' => Carbon::today(config('app.timezone')), 'expires' => Carbon::today(config('app.timezone'))->addYears(2)->subDay()], $sample, 'RS001', null, 'https://wims.invalid/id-verify/preview'), 'web') : '';

        return Inertia::render('IdPasses/Settings', [
            'categories' => $cats, 'kinds' => collect(IdPassCategory::KINDS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'icons' => $this->icons(), 'values' => IdPassSettings::all(), 'defaults' => IdPassSettings::DEFAULTS + ['idpass_terms' => implode("\n", IdPassSettings::TERMS)],
            'contactCategories' => collect(Contact::categoryOptions())->map(fn ($c) => is_array($c) ? ($c['value'] ?? $c['name'] ?? '') : $c)->filter()->values(), 'backHtml' => $back, 'css' => CardRenderer::stylesheet('web'),
            'can' => ['settings' => $u->can('manage-id-settings'), 'templates' => $u->can('manage-id-templates'), 'numbering' => $u->can('manage-id-numbering'), 'qr' => $u->can('manage-id-qr-settings'), 'alerts' => $u->can('manage-id-notifications')],
        ]);
    }

    /** How a card of each category looks with the settings it has now (a sample holder, the number that comes next): the small ID in the Categories table zooms into this. */
    public function samples(Request $request)
    {
        $this->gate();
        $today = Carbon::today(config('app.timezone'));
        $out = [];
        foreach (IdPassCategory::query()->orderBy('sort_order')->get() as $c) {
            $with = IdPassCard::query()->where('category_id', $c->id)->whereNotNull('photo_path')->latest('id')->first();
            $expires = $c->validity_value ? match ($c->validity_unit) {
                'days' => $today->copy()->addDays((int) $c->validity_value), 'weeks' => $today->copy()->addWeeks((int) $c->validity_value), 'months' => $today->copy()->addMonths((int) $c->validity_value), default => $today->copy()->addYears((int) $c->validity_value),
            } : null;
            $data = CardRenderer::draft(['full_name' => __('Sample Holder'), 'title' => $c->kind === 'staff' ? __('Position') : null, 'issued' => $today, 'expires' => $expires ? $expires->subDay() : null, 'host_name' => $c->kind === 'visitor' ? __('Host name') : null,
                'vehicle_registration' => $c->vehicle_required ? 'T 000 AAA' : null, 'authorisation_type' => $c->kind === 'gate' ? __('Access') : null], $c, $c->format((int) $c->next_number), $with ? CardAssets::photo($with) : null, 'https://wims.invalid/id-verify/preview');
            $out[$c->id] = ['front' => CardRenderer::front($data, 'web'), 'back' => CardRenderer::back($data, 'web')];
        }

        return response()->json(['css' => CardRenderer::stylesheet('web'), 'cards' => (object) $out])->header('Cache-Control', 'private, no-store');
    }

    public function general(Request $request)
    {
        $this->gate();
        $this->need('manage-id-settings');
        $d = $request->validate(['idpass_intro' => 'required|string|max:300', 'idpass_terms' => 'required|string|max:1500', 'idpass_address' => 'nullable|string|max:120', 'idpass_pobox' => 'nullable|string|max:120', 'idpass_phones' => 'nullable|string|max:120',
            'idpass_email' => 'nullable|string|max:120', 'idpass_website' => 'nullable|string|max:120', 'idpass_back_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'idpass_expiry_notice_days' => 'required|integer|min:0|max:180',
            'idpass_gate_types' => 'nullable|string|max:600', 'idpass_no_expiry_text' => 'required|string|max:40', 'idpass_stock_holder' => 'required|string|max:120', 'idpass_sync_batch' => 'required|integer|min:5|max:200'],
            ['idpass_intro.required' => __('Type the statement at the top of the back.'), 'idpass_terms.required' => __('Type the terms, one on each line.')]);
        $terms = array_values(array_filter(array_map('trim', preg_split('/\R/', $d['idpass_terms']))));
        if (count($terms) > 10) {
            throw ValidationException::withMessages(['idpass_terms' => __('The back has room for up to 10 terms.')]);
        }
        $before = IdPassSettings::all();
        $d['idpass_terms'] = implode("\n", $terms);
        IdPassSettings::save($d, (int) $request->user()->account_id);
        log_activity('RANS ID settings changed', ['before' => array_intersect_key($before, $d), 'after' => $d], null, 'RANS ID & Pass Management');

        return back()->with('message', __('Saved.'));
    }

    public function qr(Request $request)
    {
        $this->gate();
        $this->need('manage-id-qr-settings');
        $d = $request->validate(['idpass_qr_mode' => 'required|in:public,login']);
        $before = IdPassSettings::qrMode();
        IdPassSettings::save($d, (int) $request->user()->account_id);
        log_activity('RANS ID QR verification changed', ['before' => $before, 'after' => $d['idpass_qr_mode']], null, 'RANS ID & Pass Management');

        return back()->with('message', __('Saved.'));
    }

    private function colour(): array
    {
        return ['required', 'regex:/^#[0-9a-fA-F]{6}$/'];
    }

    public function store(Request $request)
    {
        $this->gate();
        $this->need('manage-id-settings');
        $this->need('manage-id-templates');
        $this->need('manage-id-numbering');
        $d = $request->validate(['kind' => 'required|in:staff,compound,visitor,gate', 'name' => 'required|string|max:80', 'label' => 'nullable|string|max:40', 'prefix' => ['nullable', 'regex:/^[A-Za-z0-9-]{0,12}$/'], 'digits' => 'required|integer|min:1|max:8',
            'band_color' => $this->colour(), 'arrow_color' => $this->colour(), 'photo_background' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'icon' => 'nullable|string|max:40', 'contact_categories' => 'nullable|array', 'contact_categories.*' => 'string|max:80', 'validity_value' => 'nullable|integer|min:1|max:3650',
            'validity_unit' => 'required|in:days,months,years', 'requires_approval' => 'boolean', 'photo_required' => 'boolean', 'vehicle_required' => 'boolean', 'pooled' => 'boolean'], ['name.required' => __('Type the name of the category.')]);
        $key = Str::slug($d['name']) ?: 'category';
        $base = $key;
        for ($i = 2; IdPassCategory::query()->where('key', $key)->exists(); $i++) {
            $key = $base . '-' . $i;
        }
        if (! empty($d['icon']) && ! in_array($d['icon'], $this->icons(), true)) {
            throw ValidationException::withMessages(['icon' => __('Choose an icon from the list.')]);
        }
        $c = IdPassCategory::create(['kind' => $d['kind'], 'key' => $key, 'name' => trim($d['name']), 'label' => $d['label'] ?? null, 'prefix' => strtoupper((string) ($d['prefix'] ?? '')), 'digits' => $d['digits'], 'next_number' => 1, 'band_color' => $d['band_color'], 'arrow_color' => $d['arrow_color'], 'photo_background' => ! empty($d['photo_background']) ? strtolower($d['photo_background']) : null,
            'icon' => $d['icon'] ?? null, 'contact_categories' => $d['kind'] === 'compound' ? array_values(array_filter($d['contact_categories'] ?? [])) : null, 'validity_value' => $d['validity_value'] ?? null, 'validity_unit' => $d['validity_unit'],
            'requires_approval' => (bool) ($d['requires_approval'] ?? false), 'photo_required' => (bool) ($d['photo_required'] ?? false), 'vehicle_required' => (bool) ($d['vehicle_required'] ?? false), 'pooled' => $d['kind'] === 'visitor' && (bool) ($d['pooled'] ?? false), 'is_active' => true, 'sort_order' => (int) IdPassCategory::query()->max('sort_order') + 1]);
        log_activity('RANS ID category added', ['name' => $c->name, 'kind' => $c->kind], $c, 'RANS ID & Pass Management');

        return back()->with('message', __(':n added.', ['n' => $c->name]));
    }

    /** Each group of fields is changed only by the permission that owns it. */
    public function update(Request $request, int $category)
    {
        $this->gate();
        $u = $request->user();
        $c = IdPassCategory::query()->findOrFail($category);
        $before = $c->only(['name', 'label', 'band_color', 'arrow_color', 'photo_background', 'icon', 'prefix', 'digits', 'next_number', 'validity_value', 'validity_unit', 'requires_approval', 'photo_required', 'vehicle_required', 'pooled', 'is_active', 'contact_categories', 'sort_order']);
        $set = [];
        if ($u->can('manage-id-templates')) {
            $d = $request->validate(['name' => 'required|string|max:80', 'label' => 'nullable|string|max:40', 'band_color' => $this->colour(), 'arrow_color' => $this->colour(), 'photo_background' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'icon' => 'nullable|string|max:40']);
            if (! empty($d['icon']) && ! in_array($d['icon'], $this->icons(), true)) {
                throw ValidationException::withMessages(['icon' => __('Choose an icon from the list.')]);
            }
            $set += ['name' => trim($d['name']), 'label' => $d['label'] ?? null, 'band_color' => $d['band_color'], 'arrow_color' => $d['arrow_color'], 'photo_background' => ! empty($d['photo_background']) ? strtolower($d['photo_background']) : null, 'icon' => $d['icon'] ?? null];
        }
        if ($u->can('manage-id-numbering')) {
            $min = (int) DB::table('idpass_numbers')->where('category_id', $c->id)->max('seq_no') + 1;
            $d = $request->validate(['prefix' => ['nullable', 'regex:/^[A-Za-z0-9-]{0,12}$/'], 'digits' => 'required|integer|min:1|max:8', 'next_number' => 'required|integer|min:' . $min . '|max:99999999'],
                ['next_number.min' => __('The next number cannot be lower than :n: numbers already given out are never used again.', ['n' => $min])]);
            $set += ['prefix' => strtoupper((string) ($d['prefix'] ?? '')), 'digits' => $d['digits'], 'next_number' => $d['next_number']];
        }
        if ($u->can('manage-id-settings')) {
            $d = $request->validate(['validity_value' => 'nullable|integer|min:1|max:3650', 'validity_unit' => 'required|in:days,months,years', 'requires_approval' => 'boolean', 'photo_required' => 'boolean', 'vehicle_required' => 'boolean', 'pooled' => 'boolean',
                'is_active' => 'boolean', 'contact_categories' => 'nullable|array', 'contact_categories.*' => 'string|max:80', 'sort_order' => 'nullable|integer|min:0|max:999']);
            $set += ['validity_value' => $d['validity_value'] ?? null, 'validity_unit' => $d['validity_unit'], 'requires_approval' => (bool) ($d['requires_approval'] ?? false), 'photo_required' => (bool) ($d['photo_required'] ?? false),
                'vehicle_required' => (bool) ($d['vehicle_required'] ?? false), 'pooled' => $c->kind === 'visitor' && (bool) ($d['pooled'] ?? false), 'is_active' => (bool) ($d['is_active'] ?? true), 'sort_order' => (int) ($d['sort_order'] ?? $c->sort_order)];
            if ($c->kind === 'compound') {
                $set['contact_categories'] = array_values(array_filter($d['contact_categories'] ?? []));
            }
        }
        abort_if(! $set, 403);
        $c->update($set);
        $after = array_intersect_key($c->fresh()->only(array_keys($before)), $set);
        log_activity('RANS ID category changed', ['category' => $c->name, 'before' => array_intersect_key($before, $set), 'after' => $after], $c, 'RANS ID & Pass Management');
        if (array_key_exists('next_number', $set) || array_key_exists('prefix', $set)) {
            log_activity('RANS ID numbering changed', ['category' => $c->name, 'prefix' => $c->prefix, 'digits' => $c->digits, 'next' => $c->next_number], $c, 'RANS ID & Pass Management');
        }
        if (array_key_exists('photo_background', $set) && ($before['photo_background'] ?? null) !== ($set['photo_background'] ?? null)) {
            log_activity('RANS ID photo background changed', ['category' => $c->name, 'from' => $before['photo_background'] ?? null, 'to' => $set['photo_background']], $c, 'RANS ID & Pass Management');
        }
        if (array_key_exists('band_color', $set) || array_key_exists('icon', $set) || array_key_exists('label', $set)) {
            log_activity('RANS ID card template changed', ['category' => $c->name], $c, 'RANS ID & Pass Management');
        }

        return back()->with('message', __('Saved.'));
    }

    /**
     * Puts the photographs of the IDs already issued on the colour of the category: cards that kept their cut-out are re-coloured here, photographs already on the colour are left alone,
     * and the rest are listed so the browser (which holds the cut-out engine) can do them one by one.
     */
    public function applyPhotoBackground(Request $request, int $category)
    {
        $this->gate();
        $this->need('manage-id-templates');
        $c = IdPassCategory::query()->findOrFail($category);
        abort_unless(PhotoBackground::valid($c->photo_background), 422, __('Choose a photo background colour for this category first.'));
        $out = ['recoloured' => 0, 'kept' => 0, 'no_photo' => 0, 'pending' => []];
        IdPassCard::query()->where('category_id', $c->id)->whereIn('status', IdPassCard::OPEN)->whereNotNull('photo_path')->orderBy('id')->chunkById(200, function ($cards) use (&$out, $c) {
            foreach ($cards as $card) {
                $r = PhotoBackground::apply($card, $c->photo_background);
                if ($r === 'needs_browser') {
                    $out['pending'][] = ['id' => $card->id, 'number' => $card->number, 'name' => $card->full_name, 'photo' => route('id-passes.' . IdPassKinds::get($card->kind)['route'] . '.photo-file', [$card->id]), 'save' => route('id-passes.' . IdPassKinds::get($card->kind)['route'] . '.photo', [$card->id])];
                } else {
                    $out[$r]++;
                }
            }
        });
        log_activity('RANS ID photo background applied', ['category' => $c->name, 'colour' => $c->photo_background, 'recoloured' => $out['recoloured'], 'kept' => $out['kept'], 'for_browser' => count($out['pending'])], $c, 'RANS ID & Pass Management');

        return response()->json($out + ['colour' => $c->photo_background]);
    }

    public function destroy(Request $request, int $category)
    {
        $this->gate();
        $this->need('manage-id-settings');
        $c = IdPassCategory::query()->findOrFail($category);
        if (IdPassCard::withTrashed()->where('category_id', $c->id)->exists() || DB::table('idpass_numbers')->where('category_id', $c->id)->exists()) {
            return back()->withErrors(['category' => __(':n has issued IDs, so it cannot be deleted. Switch it off instead.', ['n' => $c->name])]);
        }
        $c->delete();
        log_activity('RANS ID category deleted', ['name' => $c->name], $c, 'RANS ID & Pass Management');

        return back()->with('message', __(':n deleted.', ['n' => $c->name]));
    }
}
