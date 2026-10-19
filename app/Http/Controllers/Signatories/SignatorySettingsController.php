<?php

namespace App\Http\Controllers\Signatories;

use App\Services\Signatories\SignatorySettings;
use App\Services\Workflow\WorkflowMessenger;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Signatory Settings: every standard in one place (ink colour, size limits, retention, report sizes, verification rules). */
class SignatorySettingsController extends SignatoryBaseController
{
    public function index(Request $request)
    {
        $user = $this->allow($request, 'manage-signatory-settings', 'signatories_settings');

        return Inertia::render('Signatories/Settings', ['prefs' => SignatorySettings::get((int) $user->account_id), 'defaults' => config('signatories.defaults'), 'can' => ['workflow' => $user->hasRole('Super Admin')]]);
    }

    public function update(Request $request)
    {
        $user = $this->allow($request, 'manage-signatory-settings', 'signatories_settings');
        $data = $request->validate([
            'ink_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'allowed_formats' => ['required', 'array', 'min:1'], 'allowed_formats.*' => ['in:jpg,jpeg,png,webp'],
            'max_upload_mb' => ['required', 'integer', 'between:1,25'], 'min_input_px' => ['required', 'integer', 'between:80,2000'], 'max_input_px' => ['required', 'integer', 'between:1000,12000'],
            'output_width_px' => ['required', 'integer', 'between:300,2400'], 'padding_pct' => ['required', 'numeric', 'between:0,25'], 'sensitivity' => ['required', 'numeric', 'between:0.5,1.8'],
            'min_contrast' => ['required', 'numeric', 'between:0.05,0.6'], 'min_ink_coverage_pct' => ['required', 'numeric', 'between:0.02,5'], 'max_ink_coverage_pct' => ['required', 'numeric', 'between:5,60'],
            'camera_enabled' => ['required', 'boolean'], 'draw_enabled' => ['required', 'boolean'], 'self_registration' => ['required', 'in:self,admin'],
            'verification_required' => ['required', 'boolean'], 'consent_required' => ['required', 'boolean'], 'reauth_sensitive' => ['required', 'boolean'],
            'original_retention_days' => ['required', 'integer', 'between:0,3650'], 'version_retention' => ['required', 'regex:/^(all|[1-9][0-9]?)$/'],
            'report_signature_width_mm' => ['required', 'numeric', 'between:15,80'], 'report_signature_height_mm' => ['required', 'numeric', 'between:6,40'],
            'date_format' => ['required', 'in:d M Y,d/m/Y,Y-m-d,j F Y'], 'time_format' => ['required', 'in:H:i,g:i A,H:i:s'],
            'default_missing_behaviour' => ['required', 'in:blank,flag,block'], 'show_name' => ['required', 'boolean'], 'show_designation' => ['required', 'boolean'], 'show_datetime' => ['required', 'boolean'], 'apply_to_older_records' => ['required', 'boolean'], 'show_on_draft' => ['required', 'boolean'],
        ]);
        $before = SignatorySettings::get((int) $user->account_id);
        $after = SignatorySettings::save($data, (int) $user->account_id);
        log_activity('Signatory settings changed', ['before' => $before, 'after' => $after], null, 'Signatories');
        WorkflowMessenger::dispatch('signatories', 'settings_changed', null, $user, ['account_id' => $user->account_id, 'title' => __('Signatory settings changed'), 'message' => __('The Signatories settings were changed by :n.', ['n' => $user->name]), 'url' => route('signatories.settings.index'), 'dedupe' => 'signatories|settings|' . intdiv(time(), 30)]);

        return back()->with('message', __('Settings saved.'));
    }
}
