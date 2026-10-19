<?php

use App\Models\WorkflowNotificationRule;
use App\Services\Workflow\WorkflowSettings;
use App\Support\Workflow\WorkflowModules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the notification rules that Archive, Site Diary, Site Meetings and WIMSChat kept on their own settings pages into their new
 * workflow pages (workflow_notification_rules), so nothing has to be typed again. Only events that have no workflow row yet get one; the
 * old tables / settings are kept (the Archive and Site Diary schedules still live there). Digital Attendance never had rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_notification_rules')) {
            return;
        }
        try {
            DB::transaction(function () {
                $this->fromTable('archive_notification_rules', 'archive', fn (string $k) => $k);
                $this->fromTable('site_diary_notification_rules', 'site_diary', fn (string $k) => strtolower($k));
                $this->siteMeetings();
                $this->wimsChat();
            });
        } catch (\Throwable $e) {
            Log::error('Group C notification rules could not be copied into the workflow pages', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // the workflow settings are kept; nothing to undo
    }

    private function exists(int $account, string $module, string $event): bool
    {
        return WorkflowNotificationRule::where('account_id', $account)->where('module', $module)->where('event_key', $event)->exists();
    }

    /** One workflow row from the page's starting values with the given overrides. */
    private function put(int $account, string $module, string $event, array $over): void
    {
        if (! isset(WorkflowModules::get($module)['events'][$event]) || $this->exists($account, $module, $event)) {
            return;
        }
        $e = WorkflowSettings::effective($module, $account, $event, null);
        $data = ['account_id' => $account, 'module' => $module, 'event_key' => $event, 'role_names' => $e['role_names'], 'user_ids' => $e['user_ids']]
            + array_intersect_key($e, array_flip(WorkflowSettings::FLAGS));
        WorkflowNotificationRule::create($over + $data);
    }

    /** The module's own rule table (enabled / bell / email / roles / people per event). */
    private function fromTable(string $table, string $module, callable $eventKey): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach (DB::table($table)->get() as $r) {
            $roles = json_decode($r->role_names ?? '[]', true) ?: [];
            $users = array_map('intval', json_decode($r->user_ids ?? '[]', true) ?: []);
            $this->put((int) $r->account_id, $module, $eventKey($r->event_key), [
                'is_enabled' => (bool) $r->enabled && ((bool) $r->notify_bell || (bool) $r->send_email),
                'bell_enabled' => (bool) $r->notify_bell, 'email_enabled' => (bool) $r->send_email,
                'role_names' => array_values($roles), 'user_ids' => array_values($users),
            ]);
        }
    }

    /** Site Meetings keeps its rules inside the settings table: one JSON value plus the older on/off switches. */
    private function siteMeetings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        $accounts = DB::table('settings')->where('tec_key', 'like', 'site_meetings_%')
            ->whereIn('tec_key', ['site_meetings_notification_rules', 'site_meetings_notify_on_schedule', 'site_meetings_notify_on_change', 'site_meetings_notify_on_report', 'site_meetings_send_email'])
            ->pluck('account_id')->unique();

        foreach ($accounts as $account) {
            $rows = DB::table('settings')->where('account_id', $account)->where('tec_key', 'like', 'site_meetings_%')->pluck('tec_value', 'tec_key');
            $flag = fn (string $k, bool $default) => isset($rows['site_meetings_' . $k]) ? (bool) (int) $rows['site_meetings_' . $k] : $default;
            $saved = json_decode($rows['site_meetings_notification_rules'] ?? 'null', true);
            $saved = is_array($saved) ? $saved : [];
            $legacy = [
                'scheduled' => $flag('notify_on_schedule', true), 'changed' => $flag('notify_on_change', true), 'status' => $flag('notify_on_change', true), 'deleted' => $flag('notify_on_change', true),
                'minutes_submitted' => $flag('notify_on_report', true), 'minutes_finalised' => $flag('notify_on_report', true), 'minutes_reopened' => $flag('notify_on_report', true), 'reminder' => true,
            ];
            foreach ($legacy as $event => $on) {
                $r = is_array($saved[$event] ?? null) ? $saved[$event] : null;
                $bell = $r ? (bool) ($r['bell'] ?? false) : $on;
                $sms = $r ? (bool) ($r['sms'] ?? false) : false;
                $email = $r ? (bool) ($r['email'] ?? false) : ($flag('send_email', false) && $on);
                $who = $r ? (array) ($r['who'] ?? []) : null; // null = the starting groups of the event
                $this->put((int) $account, 'site_meetings', $event, [
                    'is_enabled' => $bell || $sms || $email, 'bell_enabled' => $bell, 'sms_enabled' => $sms, 'email_enabled' => $email,
                    'notify_owner' => $who === null ? true : count($who) > 0,
                    'role_names' => array_values(array_filter(array_map('strval', (array) ($r['roles'] ?? [])))),
                    'user_ids' => array_values(array_filter(array_map('intval', (array) ($r['users'] ?? [])))),
                ]);
            }
        }
    }

    /** WIMSChat keeps which events notify (and which also send e-mail) in the settings table. */
    private function wimsChat(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }
        $accounts = DB::table('settings')->whereIn('tec_key', ['wimschat_notify_events', 'wimschat_notify_email_events'])->pluck('account_id')->unique();
        // old notify_events keys => the workflow events they switch
        $map = [
            'offline_message' => ['message' => 'MESSAGE'], 'mention' => ['mention' => 'MENTION'], 'reply' => ['reply' => 'REPLY'], 'nudge' => ['nudge' => 'NUDGE'],
            'group' => ['group_invite' => 'GROUP_INVITE', 'group_added' => 'GROUP_ADDED', 'group_removed' => 'GROUP_REMOVED', 'group_joined' => 'GROUP_JOINED'],
        ];
        $defaults = ['offline_message' => true, 'mention' => true, 'reply' => true, 'nudge' => true, 'group' => true];

        foreach ($accounts as $account) {
            $rows = DB::table('settings')->where('account_id', $account)->whereIn('tec_key', ['wimschat_notify_events', 'wimschat_notify_email_events'])->pluck('tec_value', 'tec_key');
            $events = array_merge($defaults, (array) json_decode($rows['wimschat_notify_events'] ?? 'null', true));
            $emails = (array) json_decode($rows['wimschat_notify_email_events'] ?? 'null', true);
            foreach ($map as $old => $targets) {
                foreach ($targets as $event => $type) {
                    $this->put((int) $account, 'wimschat', $event, ['is_enabled' => ! empty($events[$old]), 'bell_enabled' => true, 'email_enabled' => in_array($type, $emails, true), 'notify_owner' => true]);
                }
            }
        }
    }
};
