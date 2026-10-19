<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\Workflow\WorkflowReach;
use App\Services\Workflow\WorkflowSettings;
use App\Support\MaterialRequestMessageTags;
use App\Support\Workflow\WorkflowModules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

/**
 * The Alerts page of every module (Who Gets Notified, SMS & Email). One controller for all of them; the module comes from the route
 * default. Who may DO something is decided only on the Roles screen (permissions); this page only decides who is told. Every save
 * re-verifies the signed-in person's own password.
 */
class WorkflowSettingsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function module(Request $request): array
    {
        $key = (string) $request->route('module');
        abort_unless(in_array($key, WorkflowModules::keys(), true), 404);
        $user = $request->user();
        $cfg = WorkflowModules::get($key);
        $perm = str_starts_with((string) $cfg['permission'], 'perm:') ? substr($cfg['permission'], 5) : null;
        abort_unless($user->hasRole('Super Admin') || ($perm && $user->can($perm)), 403);

        return $cfg;
    }

    public function index(Request $request)
    {
        $cfg = $this->module($request);
        $accountId = $request->user()->account_id;
        $roles = Role::ofAccount()->orderBy('name')->pluck('name');

        WorkflowSettings::memoise(true); // the page reads each module's rules once instead of once per event
        try {
            return $this->page($cfg, $accountId, $roles);
        } finally {
            WorkflowSettings::memoise(false);
        }
    }

    private function page(array $cfg, $accountId, $roles)
    {
        return Inertia::render('Administration/WorkflowSettings', [
            'module' => [
                'key' => $cfg['key'], 'title' => $cfg['title'], 'subtitle' => $cfg['subtitle'], 'record' => $cfg['record'], 'ownerLabel' => $cfg['owner_label'], 'stepNote' => $cfg['step_note'],
                'updateRoute' => $cfg['update_route'],
            ],
            'notificationRules' => array_values(WorkflowSettings::rules($cfg['key'], $accountId, $roles->all())),
            'reach' => WorkflowReach::forModule($cfg['key'], (int) $accountId),
            'messageTags' => $cfg['tags'],
            'roles' => $roles,
            'users' => User::ofAccount()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request)
    {
        $cfg = $this->module($request);
        $user = $request->user();

        $data = $request->validate([
            'password' => ['required', 'string'],
            'notificationRules' => ['nullable', 'array'],
            'notificationRules.*.event_key' => ['required', 'string', 'in:' . implode(',', array_keys($cfg['events']))],
            'notificationRules.*.role_names' => ['nullable', 'array'],
            'notificationRules.*.role_names.*' => ['string'],
            'notificationRules.*.user_ids' => ['nullable', 'array'],
            'notificationRules.*.user_ids.*' => ['integer'],
            'notificationRules.*.is_enabled' => ['boolean'],
            'notificationRules.*.notify_owner' => ['boolean'],
            'notificationRules.*.notify_step_owner' => ['boolean'],
            'notificationRules.*.notify_everyone' => ['boolean'],
            'notificationRules.*.limit_to_project' => ['boolean'],
            'notificationRules.*.bell_enabled' => ['boolean'],
            'notificationRules.*.sms_enabled' => ['boolean'],
            'notificationRules.*.email_enabled' => ['boolean'],
            'notificationRules.*.whatsapp_enabled' => ['boolean'],
            'notificationRules.*.meta_enabled' => ['boolean'],
            'notificationRules.*.sms_template' => ['nullable', 'string', 'max:1000'],
            'notificationRules.*.email_subject' => ['nullable', 'string', 'max:255'],
            'notificationRules.*.email_body' => ['nullable', 'string', 'max:5000'],
            'notificationRules.*.whatsapp_template' => ['nullable', 'string', 'max:2000'],
            'notificationRules.*.meta_template' => ['nullable', 'string', 'max:2000'],
            'notificationRules.*.email_show_details' => ['boolean'],
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['password' => [__('The password you entered is incorrect.')]]);
        }

        DB::transaction(function () use ($data, $cfg, $user) {
            if (! empty($data['notificationRules'])) {
                WorkflowSettings::save($cfg['key'], $user->account_id, $data['notificationRules']);
            }
        });

        log_activity(__(':t updated.', ['t' => __($cfg['title'])]), ['module' => $cfg['key']], $user, ucfirst($cfg['key']));

        // the shared notification alert (top right) tells the person it was saved
        return back()->with('message', __('Alert settings saved.'));
    }
}
