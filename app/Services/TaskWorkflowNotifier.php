<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\Workflow\WorkflowMessenger;
use App\Services\Workflow\WorkflowSettings;
use App\Support\Workflow\WorkflowModules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hands every Task Manager notice to the Task Manager workflow page's settings (Who Gets Notified / Messages). It replaces the older
 * TaskSmsNotifier / TaskSuperadminAlertNotifier senders. Events: assigned, status_changed, commented, due_reminder, deleted,
 * superadmin_alert, superadmin_sms, personal_reminder, personal_reminder_email.
 *
 * "The owner" of a task event is its creator plus its current assignees (for "assigned": only the people just assigned).
 * The Super Admin copy is the old "Superadmin Alerts" guarantee: a Super Admin who created or holds a task hears about it by app + email
 * (unless the regular event already emailed them) and, for assigned / status changed / due, by SMS (unless the regular event already texted them).
 * Assignees that are Staff or Contacts (not users) keep their older email-only path.
 */
class TaskWorkflowNotifier
{
    public const MODULE = 'task_manager';

    private const SUPER_ADMIN_EVENTS = ['assigned', 'status_changed', 'commented', 'deleted', 'due_reminder'];

    private const OWN_ACTION_EVENTS = ['status_changed', 'commented', 'deleted', 'created', 'updated', 'unassigned', 'checklist_changed'];

    public static function accountId(Task $task): int
    {
        return (int) ($task->createdBy?->account_id ?? optional($task->warehouse)->account_id ?? 1);
    }

    /**
     * $o: new_users (assigned: the people just assigned), values (extra #tags), smsline (the Super Admin SMS), dedupe,
     * only (limit to these users, for due reminders already sent to others).
     */
    public static function fire(string $event, Task $task, ?User $actor, string $title, string $message, array $o = []): void
    {
        try {
            [$ctx, $sas, $assignees] = self::build($event, $task, $actor, $title, $message, $o);
            $account = $ctx['account_id'];
            $def = WorkflowModules::get(self::MODULE)['events'];

            WorkflowMessenger::dispatch(self::MODULE, $event, $task, $actor, $ctx);

            // assignees that are not users (Staff / Contact) keep their older email-only notice
            if (in_array($event, ['status_changed', 'commented'], true)) {
                $rule = WorkflowSettings::effective(self::MODULE, $account, $event);
                if ($rule['is_enabled'] && $rule['notify_owner'] && $rule['email_enabled']) {
                    foreach ($assignees->reject(fn ($a) => $a instanceof User) as $other) {
                        $other->notify(new AppNotification([
                            'module' => 'task_manager', 'type' => 'task_manager_' . $event, 'title' => $title, 'message' => $message,
                            'status' => $task->status, 'reference' => $task->title, 'url' => $ctx['url'], 'send_email' => true, 'created_at' => now()->toDateTimeString(),
                        ]));
                    }
                }
            }

            if (! in_array($event, self::SUPER_ADMIN_EVENTS, true) || $sas->isEmpty()) {
                return;
            }

            // who the regular event already reached, per channel: they are not told a second time on that channel
            $rule = WorkflowSettings::effective(self::MODULE, $account, $event);
            $reached = $rule['is_enabled'] ? WorkflowMessenger::audience(self::MODULE, $rule, $def[$event], $account, 0, $actor, $ctx, $task)->pluck('id')->map(fn ($i) => (int) $i) : collect();
            $emailed = $rule['email_enabled'] ? $reached : collect();
            $texted = $rule['sms_enabled'] ? $reached : collect();

            $alertTo = $sas->reject(fn ($u) => $emailed->contains((int) $u->id))->values();
            if ($alertTo->isNotEmpty()) {
                WorkflowMessenger::dispatch(self::MODULE, 'superadmin_alert', $task, $actor, ['owner_users' => $alertTo->all()] + $ctx);
            }
            if (! empty($o['smsline']) && in_array($event, ['assigned', 'status_changed', 'due_reminder'], true)) {
                $smsTo = $sas->reject(fn ($u) => $texted->contains((int) $u->id))->values();
                if ($smsTo->isNotEmpty()) {
                    WorkflowMessenger::dispatch(self::MODULE, 'superadmin_sms', $task, $actor, ['owner_users' => $smsTo->all()] + $ctx);
                }
            }
        } catch (\Throwable $e) {
            Log::error('TaskWorkflowNotifier failed', ['task' => $task->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** A Task Manager settings change (the reminder timing): not about one task. */
    public static function settings(string $message, ?User $actor): void
    {
        try {
            WorkflowMessenger::dispatch(self::MODULE, 'settings_changed', null, $actor, [
                'account_id' => (int) ($actor?->account_id ?? 1), 'title' => __('Task Manager Settings Changed'), 'message' => $message, 'url' => route('task-manager.index'),
                'skip_actor' => true, 'dedupe' => md5($message) . '|' . intdiv(time(), 20),
                'values' => ['task' => '', 'status' => '', 'assignees' => '', 'duelabel' => '', 'duetext' => '', 'when' => '', 'commentshort' => '', 'comment' => '', 'smsline' => ''],
            ]);
        } catch (\Throwable $e) {
            Log::error('TaskWorkflowNotifier settings failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Everyone a due reminder of this task can reach right now (the event's audience plus the Super Admin copies), for the once-only
     * guard of the reminder command. Empty when every reminder event is switched off.
     */
    public static function reminderPeople(Task $task): Collection
    {
        [$ctx, $sas] = self::build('due_reminder', $task, null, '', '', []);
        $account = $ctx['account_id'];
        $def = WorkflowModules::get(self::MODULE)['events'];
        $rule = WorkflowSettings::effective(self::MODULE, $account, 'due_reminder');
        $people = $rule['is_enabled'] ? WorkflowMessenger::audience(self::MODULE, $rule, $def['due_reminder'], $account, 0, null, $ctx, $task) : collect();
        $saOn = WorkflowSettings::effective(self::MODULE, $account, 'superadmin_alert')['is_enabled'] || WorkflowSettings::effective(self::MODULE, $account, 'superadmin_sms')['is_enabled'];

        return $people->merge($saOn ? $sas : collect())->unique('id')->values();
    }

    /** A personal reminder (Task Reminders) reaches its time: the in-app notice, and the email copy only for people who opted in on their profile. */
    public static function personalReminder(TaskReminder $reminder): void
    {
        try {
            $user = $reminder->user;
            if (! $user) {
                return;
            }
            $title = (string) $reminder->name;
            $message = $reminder->notes ? Str::limit($reminder->notes, 100) : __('Your reminder is due now.');
            $ctx = [
                'account_id' => (int) ($reminder->account_id ?: $user->account_id ?: 1), 'title' => $title, 'message' => $message,
                'url' => route('task-reminders.index', ['highlight' => $reminder->id]), 'owner_user_id' => (int) $user->id, 'dedupe' => 'reminder' . $reminder->id,
                'values' => ['task' => $title],
            ];
            WorkflowMessenger::dispatch(self::MODULE, 'personal_reminder', $reminder, null, $ctx);
            if ($user->task_reminders_email_enabled) { // the person's own opt-in
                WorkflowMessenger::dispatch(self::MODULE, 'personal_reminder_email', $reminder, null, $ctx);
            }
        } catch (\Throwable $e) {
            Log::error('TaskWorkflowNotifier reminder failed', ['reminder' => $reminder->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return array{0: array, 1: Collection, 2: Collection} the dispatch context, the Super Admins concerned, all assignees */
    private static function build(string $event, Task $task, ?User $actor, string $title, string $message, array $o): array
    {
        $task->loadMissing(['createdBy', 'assignees.assignee', 'warehouse']);
        $assignees = $task->assignees->map(fn ($ta) => $ta->assignee)->filter()->values();
        $users = $assignees->filter(fn ($a) => $a instanceof User)->values();
        $names = $assignees->pluck('name')->filter()->implode(', ');
        $only = isset($o['only']) ? collect($o['only'])->pluck('id')->map(fn ($i) => (int) $i) : null;

        $owners = in_array($event, ['assigned', 'unassigned'], true)
            ? collect($o['new_users'] ?? [])
            : collect([$task->createdBy])->merge($users)->filter()->unique('id');
        $sas = collect([$task->createdBy])->merge($users)->filter(fn ($u) => $u && $u->hasRole('Super Admin'))->unique('id')
            ->when($actor, fn (Collection $c) => $c->reject(fn ($u) => (int) $u->id === (int) $actor->id));
        if ($only && $only->isNotEmpty()) {
            $owners = $owners->filter(fn ($u) => $only->contains((int) $u->id));
            $sas = $sas->filter(fn ($u) => $only->contains((int) $u->id));
        }

        $dueAt = null;
        if ($task->due_date) {
            $dueAt = \Carbon\Carbon::parse($task->due_date->format('Y-m-d') . ' ' . ($task->due_time ? substr($task->due_time, 0, 5) : '00:00'));
        }
        $values = ($o['values'] ?? []) + [
            'task' => (string) $task->title, 'status' => str_replace('_', ' ', (string) $task->status), 'assignees' => $names,
            'duelabel' => $dueAt ? ($task->due_time ? $dueAt->format('M j, Y \a\t g:i A') : $dueAt->format('M j, Y')) : '',
            'duetext' => $task->due_date ? ' Due ' . $task->due_date->format('M j') . '.' : '',
            'smsline' => (string) ($o['smsline'] ?? ''),
        ];

        $ctx = [
            'account_id' => self::accountId($task), 'title' => $title, 'message' => $message, 'url' => route('task-manager.index', ['task' => $task->id]),
            'owner_users' => $owners->values()->all(), 'values' => $values,
            'dedupe' => (string) ($o['dedupe'] ?? ($task->id . '|' . $event . '|' . now()->format('YmdHisu'))),
            'skip_actor' => in_array($event, self::OWN_ACTION_EVENTS, true),
        ];
        if ($only && $only->isNotEmpty()) {
            // the roles / named people of the event are also limited to the people still to be reminded
            $ctx['candidates'] = User::whereIn('id', $only->all())->get()->all();
        }

        return [$ctx, $sas->values(), $assignees];
    }
}
