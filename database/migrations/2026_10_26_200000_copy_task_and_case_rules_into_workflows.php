<?php

use App\Services\Workflow\WorkflowSettings;
use App\Support\Workflow\WorkflowModules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Task Manager and Case Tracking had their own "who gets notified" tables (task_notification_rules, case_tracking_notification_rules,
 * task_superadmin_alert_settings). Their settings now live on the module workflow pages (workflow_notification_rules), so what was saved
 * is copied there: roles, named people, "creator / assignee" (-> the workflow's "owner"), email and SMS. Only events that differ from the
 * page's starting values get a row, and an event that already has a workflow row is left alone. The old tables are kept.
 *
 * Case Tracking only ever emailed on "Case Assigned" (the email box of the other events did nothing), so the email flag is copied for that
 * event only. The Super Admin Alerts switch (task_superadmin_alert_settings) becomes the on/off state of the two Super Admin alert events.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::transaction(function () {
                $this->copyRules('task_notification_rules', 'task_manager', fn (string $event, object $r) => (bool) $r->email_enabled);
                $this->copyRules('case_tracking_notification_rules', 'case_tracking', fn (string $event, object $r) => $event === 'assigned' ? (bool) $r->email_enabled : false);
                $this->copySuperAdminAlerts();
            });
        } catch (\Throwable $e) {
            Log::error('Task Manager / Case Tracking notification rules could not be copied into the workflow pages', ['error' => $e->getMessage()]);
        }
    }

    private function copyRules(string $table, string $module, \Closure $email): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable('workflow_notification_rules')) {
            return;
        }
        $events = WorkflowModules::get($module)['events'];

        foreach (DB::table($table)->get() as $r) {
            if (! isset($events[$r->event_key]) || $this->hasRow($module, (int) $r->account_id, $r->event_key)) {
                continue;
            }
            $roles = array_values(array_filter((array) json_decode((string) $r->role_names, true)));
            $users = array_values(array_map('intval', (array) json_decode((string) $r->user_ids, true)));
            $values = [
                'role_names' => $roles, 'user_ids' => $users, 'notify_owner' => (bool) ($r->notify_creator || $r->notify_assignee),
                'email_enabled' => $email($r->event_key, $r), 'sms_enabled' => (bool) ($r->sms_enabled ?? false),
            ];
            $start = WorkflowSettings::effective($module, (int) $r->account_id, $r->event_key, null);
            $same = $this->sorted($start['role_names']) === $this->sorted($roles) && $this->sorted($start['user_ids']) === $this->sorted($users)
                && (bool) $start['notify_owner'] === $values['notify_owner'] && (bool) $start['email_enabled'] === $values['email_enabled'] && (bool) $start['sms_enabled'] === $values['sms_enabled'];
            if ($same) {
                continue;
            }
            $this->insert($module, (int) $r->account_id, $r->event_key, [
                'role_names' => json_encode($roles), 'user_ids' => json_encode($users), 'notify_owner' => $values['notify_owner'],
                'email_enabled' => $values['email_enabled'], 'sms_enabled' => $values['sms_enabled'], 'bell_enabled' => (bool) $start['bell_enabled'],
                'notify_step_owner' => false, 'is_enabled' => (bool) $start['is_enabled'],
            ]);
        }
    }

    private function copySuperAdminAlerts(): void
    {
        if (! Schema::hasTable('task_superadmin_alert_settings') || ! Schema::hasTable('workflow_notification_rules')) {
            return;
        }
        foreach (DB::table('task_superadmin_alert_settings')->where('is_enabled', false)->get() as $s) {
            foreach (['superadmin_alert', 'superadmin_sms'] as $event) {
                if ($this->hasRow('task_manager', (int) $s->account_id, $event)) {
                    continue;
                }
                $start = WorkflowSettings::effective('task_manager', (int) $s->account_id, $event, null);
                $this->insert('task_manager', (int) $s->account_id, $event, [
                    'role_names' => json_encode([]), 'user_ids' => json_encode([]), 'notify_owner' => (bool) $start['notify_owner'], 'email_enabled' => (bool) $start['email_enabled'],
                    'sms_enabled' => (bool) $start['sms_enabled'], 'bell_enabled' => (bool) $start['bell_enabled'], 'notify_step_owner' => false, 'is_enabled' => false,
                ]);
            }
        }
    }

    private function hasRow(string $module, int $accountId, string $event): bool
    {
        return DB::table('workflow_notification_rules')->where('account_id', $accountId)->where('module', $module)->where('event_key', $event)->exists();
    }

    private function insert(string $module, int $accountId, string $event, array $data): void
    {
        DB::table('workflow_notification_rules')->insert($data + [
            'account_id' => $accountId, 'module' => $module, 'event_key' => $event, 'notify_everyone' => false, 'limit_to_project' => false,
            'whatsapp_enabled' => false, 'meta_enabled' => false, 'email_show_details' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sorted(array $a): array
    {
        $a = array_values($a);
        sort($a);

        return $a;
    }

    public function down(): void
    {
        // the workflow settings are kept; nothing to undo
    }
};
