<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Services\Subcontracts\SubcontractFields;
use App\Services\Subcontracts\SubcontractSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Subcontract settings: units (with the spellings that mean the same unit), work categories, payment limits and alert timing. */
class SettingsController extends Controller
{
    use SubcontractHelpers;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function edit()
    {
        $this->need('manage-subcontract-settings');
        $s = SubcontractSettings::get();
        $units = [];
        foreach ($s['units'] as $name => $aliases) {
            $units[] = ['name' => $name, 'aliases' => implode(', ', $aliases)];
        }

        $fields = [];
        foreach (SubcontractFields::entities() as $e) {
            $c = SubcontractFields::config($e);
            $fields[$e] = ['label' => __(SubcontractFields::ENTITY_LABELS[$e]), 'builtin' => collect($c['builtin'])->map(fn ($b, $k) => ['key' => $k, 'label' => __($b['label']), 'on' => $b['on'], 'required' => $b['required']])->values(), 'custom' => collect($c['custom'])->map(fn ($f) => $f + ['options_text' => implode("\n", $f['options'])])->values()];
        }

        return Inertia::render('Subcontracts/Settings', ['fields' => $fields, 'field_types' => collect(SubcontractFields::TYPES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'max_custom' => SubcontractFields::MAX_CUSTOM, 's' => ['units' => $units, 'categories' => implode("\n", $s['categories']), 'currencies' => implode(', ', $s['currencies']), 'override_allowed' => (bool) $s['override_allowed'], 'tolerance' => (string) $s['tolerance'],
            'default_retention_percent' => (string) $s['default_retention_percent'], 'review_alert_hours' => (int) $s['review_alert_hours'], 'completion_alert_days' => (int) $s['completion_alert_days'], 'alert_cooldown_hours' => (int) $s['alert_cooldown_hours'], 'unpaid_alert_days' => (int) $s['unpaid_alert_days'], 'near_limit_percent' => (string) $s['near_limit_percent'], 'dated_list_tolerance' => (string) $s['dated_list_tolerance'],
            'claims_require_verification' => (bool) $s['claims_require_verification'], 'claims_require_evidence' => (bool) $s['claims_require_evidence'], 'claims_allow_partial_request' => (bool) $s['claims_allow_partial_request']], 'workflow_url' => route('subcontracts.workflow')]);
    }

    public function update(Request $request)
    {
        $this->need('manage-subcontract-settings');
        $d = $request->validate(['units' => 'required|array|min:1|max:60', 'units.*.name' => 'required|string|max:30', 'units.*.aliases' => 'nullable|string|max:300', 'categories' => 'nullable|string|max:3000', 'currencies' => 'required|string|max:60',
            'override_allowed' => 'boolean', 'tolerance' => ['required', 'numeric', 'regex:/^\\d+(\\.\\d{1,2})?$/', 'min:0', 'max:99999999'], 'default_retention_percent' => ['required', 'numeric', 'regex:/^\\d+(\\.\\d{1,2})?$/', 'min:0', 'max:100'], 'review_alert_hours' => 'required|integer|min:1|max:2000', 'completion_alert_days' => 'required|integer|min:0|max:365', 'alert_cooldown_hours' => 'sometimes|required|integer|min:1|max:720', 'unpaid_alert_days' => 'sometimes|required|integer|min:1|max:365', 'near_limit_percent' => ['sometimes', 'required', 'numeric', 'regex:/^\\d+(\\.\\d{1,2})?$/', 'min:0', 'max:100'], 'dated_list_tolerance' => ['required', 'numeric', 'regex:/^\\d+(\\.\\d{1,2})?$/', 'min:0', 'max:100000'],
            'claims_require_verification' => 'sometimes|boolean', 'claims_require_evidence' => 'sometimes|boolean', 'claims_allow_partial_request' => 'sometimes|boolean']); // a switch that is not sent keeps its value
        $request->validate(['fields' => 'nullable|array']);
        $fields = $request->has('fields') ? SubcontractFields::sanitize((array) $request->input('fields', [])) : (SubcontractSettings::get()['fields'] ?? []);
        $units = [];
        foreach ($d['units'] as $u) {
            $units[trim($u['name'])] = array_values(array_filter(array_map('trim', explode(',', (string) ($u['aliases'] ?? '')))));
        }
        $currencies = array_values(array_unique(array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', $d['currencies'])), fn ($c) => preg_match('/^[A-Z]{3}$/', $c))));
        if (! in_array('TZS', $currencies, true)) {
            array_unshift($currencies, 'TZS');
        }
        $before = SubcontractSettings::get();
        $clean = SubcontractSettings::save(['fields' => $fields, 'units' => $units, 'categories' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($d['categories'] ?? ''))))), 'currencies' => $currencies, 'override_allowed' => (bool) ($d['override_allowed'] ?? false),
            'tolerance' => (string) $d['tolerance'], 'default_retention_percent' => (string) $d['default_retention_percent'], 'review_alert_hours' => (int) $d['review_alert_hours'], 'completion_alert_days' => (int) $d['completion_alert_days'], 'alert_cooldown_hours' => (int) ($d['alert_cooldown_hours'] ?? $before['alert_cooldown_hours']), 'unpaid_alert_days' => (int) ($d['unpaid_alert_days'] ?? $before['unpaid_alert_days']), 'near_limit_percent' => (string) ($d['near_limit_percent'] ?? $before['near_limit_percent']), 'dated_list_tolerance' => (string) $d['dated_list_tolerance'],
            'claims_require_verification' => (bool) ($d['claims_require_verification'] ?? $before['claims_require_verification']), 'claims_require_evidence' => (bool) ($d['claims_require_evidence'] ?? $before['claims_require_evidence']), 'claims_allow_partial_request' => (bool) ($d['claims_allow_partial_request'] ?? $before['claims_allow_partial_request'])]);
        log_activity('Subcontract settings changed', ['override_allowed' => [$before['override_allowed'], $clean['override_allowed']], 'tolerance' => [$before['tolerance'], $clean['tolerance']], 'claims_require_verification' => [$before['claims_require_verification'], $clean['claims_require_verification']], 'claims_require_evidence' => [$before['claims_require_evidence'], $clean['claims_require_evidence']], 'claims_allow_partial_request' => [$before['claims_allow_partial_request'], $clean['claims_allow_partial_request']], 'currencies' => $clean['currencies'], 'fields' => collect($clean['fields'])->map(fn ($e) => ['custom' => collect($e['custom'])->pluck('label')->all(), 'off' => collect($e['builtin'])->where('on', false)->keys()->all(), 'required' => collect($e['builtin'])->where('required', true)->keys()->all()])->all()], null, 'Subcontracts');

        return back()->with('message', __('Saved.'));
    }
}
