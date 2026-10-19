<?php

namespace App\Http\Controllers;

use App\Models\MaterialRequestNotificationRule;
use App\Models\MaterialRequestWorkflowStep;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Material Request NOTIFICATION settings: who gets notified at each stage, and the SMS / email messages
 * (see MaterialRequestNotificationRule). Who may DO each step and who SEES which request is no longer set here: it
 * is permissions on the Roles screen (MaterialRequestWorkflowStep::STEP_PERMISSIONS, MaterialRequestVisibility).
 * Super-Admin-only, and every save re-verifies the Super Admin's own password inline (same Hash::check pattern as
 * SettingController::updateImpersonationExpiry()) since this controls who is messaged about real requests.
 */
class MaterialRequestWorkflowController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $accountId = $request->user()->account_id;
        $roleNames = Role::ofAccount()->orderBy('name')->pluck('name');

        return Inertia::render('Administration/MaterialRequestWorkflow', [
            'notificationRules' => array_values(MaterialRequestNotificationRule::forAccount($accountId, $roleNames->all())),
            'clearDeliveryMode' => MaterialRequestWorkflowStep::clearDeliveryMode($accountId),
            'messageDefaults' => MaterialRequestNotificationRule::messageDefaults(),
            'messageTags' => \App\Support\MaterialRequestMessageTags::forAdmin(),
            'roles' => $roleNames,
            'users' => User::ofAccount()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasRole('Super Admin'), 403);

        $data = $request->validate([
            'password' => ['required', 'string'],
            'clearDeliveryMode' => ['nullable', 'in:' . implode(',', MaterialRequestWorkflowStep::CLEAR_CHECK_MODES)],
            'notificationRules' => ['nullable', 'array'],
            'notificationRules.*.event_key' => ['required', 'string', 'in:' . implode(',', array_keys(MaterialRequestNotificationRule::defaults()))],
            'notificationRules.*.role_names' => ['nullable', 'array'],
            'notificationRules.*.role_names.*' => ['string'],
            'notificationRules.*.user_ids' => ['nullable', 'array'],
            'notificationRules.*.user_ids.*' => ['integer'],
            'notificationRules.*.notify_requester' => ['boolean'],
            'notificationRules.*.notify_site_engineers' => ['boolean'],
            'notificationRules.*.sms_enabled' => ['boolean'],
            'notificationRules.*.email_enabled' => ['boolean'],
            'notificationRules.*.sms_template' => ['nullable', 'string', 'max:1000'],
            'notificationRules.*.email_subject' => ['nullable', 'string', 'max:255'],
            'notificationRules.*.email_body' => ['nullable', 'string', 'max:5000'],
            'notificationRules.*.email_show_details' => ['boolean'],
            'notificationRules.*.email_show_items' => ['boolean'],
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => [__('The password you entered is incorrect.')]]);
        }

        $accountId = $user->account_id;
        DB::transaction(function () use ($data, $accountId) {
            if (! empty($data['clearDeliveryMode'])) {
                MaterialRequestWorkflowStep::saveClearDeliveryMode($accountId, $data['clearDeliveryMode']);
            }

            foreach ($data['notificationRules'] ?? [] as $ruleData) {
                $defaults = MaterialRequestNotificationRule::defaults()[$ruleData['event_key']];

                MaterialRequestNotificationRule::updateOrCreate(
                    ['account_id' => $accountId, 'event_key' => $ruleData['event_key']],
                    [
                        'label' => $defaults['label'],
                        'description' => $defaults['description'],
                        'role_names' => array_values($ruleData['role_names'] ?? []),
                        'user_ids' => array_values($ruleData['user_ids'] ?? []),
                        'notify_requester' => (bool) ($ruleData['notify_requester'] ?? false),
                        'notify_site_engineers' => (bool) ($ruleData['notify_site_engineers'] ?? false),
                        'sms_enabled' => (bool) ($ruleData['sms_enabled'] ?? false),
                        'email_enabled' => (bool) ($ruleData['email_enabled'] ?? false),
                        // Blank = "use the built-in default text".
                        'sms_template' => filled($ruleData['sms_template'] ?? null) ? trim($ruleData['sms_template']) : null,
                        'email_subject' => filled($ruleData['email_subject'] ?? null) ? trim($ruleData['email_subject']) : null,
                        'email_body' => filled($ruleData['email_body'] ?? null) ? trim($ruleData['email_body']) : null,
                        'email_show_details' => (bool) ($ruleData['email_show_details'] ?? true),
                        'email_show_items' => (bool) ($ruleData['email_show_items'] ?? true),
                    ]
                );
            }
        });

        log_activity(
            __('Material Request notification settings updated.'),
            ['clearDeliveryMode' => $data['clearDeliveryMode'] ?? null, 'notificationRules' => $data['notificationRules'] ?? []],
            $user,
            'Material Requests'
        );

        // the shared notification alert (top right) tells the person it was saved
        return back()->with('message', __('Alert settings saved.'));
    }
}
