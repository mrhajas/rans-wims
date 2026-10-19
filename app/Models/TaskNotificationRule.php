<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Softcoded "who gets notified" for Task Manager — mirrors
 * CaseTrackingNotificationRule, adapted for Task Manager's multiple-assignee
 * model: `notify_assignee` means "the person just assigned" for the
 * assignment event, and "every current assignee" for the status-change
 * event (see recipientsForAssignment() vs recipientsForStatusChange()).
 */
class TaskNotificationRule extends Model
{
    protected $fillable = ['account_id', 'event_key', 'label', 'description', 'role_names', 'user_ids', 'notify_creator', 'notify_assignee', 'email_enabled', 'sms_enabled', 'reminder_offset_hours'];

    protected $casts = [
        'role_names' => 'array',
        'user_ids' => 'array',
        'notify_creator' => 'boolean',
        'notify_assignee' => 'boolean',
        'email_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
        'reminder_offset_hours' => 'integer',
    ];

    public const EVENT_ASSIGNED = 'assigned';
    public const EVENT_STATUS_CHANGED = 'status_changed';
    public const EVENT_COMMENTED = 'commented';
    public const EVENT_DUE_REMINDER = 'due_reminder';

    /** Valid choices for reminder_offset_hours, shown in the settings UI (0 = "On Due Date"). */
    public const REMINDER_OFFSET_HOURS = [0, 8, 12, 24];

    /** Today's default audiences — nothing changes for anyone until a Super Admin edits a rule. */
    public static function defaults(): array
    {
        return [
            self::EVENT_ASSIGNED => [
                'label' => 'Task Assigned',
                'description' => 'Notified when someone is assigned to a task.',
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => false,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_STATUS_CHANGED => [
                'label' => 'Task Status Changed',
                'description' => 'Notified whenever a task moves to a different status/column.',
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => true,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_COMMENTED => [
                'label' => 'Comment Added',
                'description' => 'Notified when someone comments on a task.',
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => true,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_DUE_REMINDER => [
                'label' => 'Task Due Reminder',
                'description' => 'Notified as a task\'s due date/time approaches or arrives. Super Admins who are the task\'s creator or an assignee always also get an SMS for this one, regardless of the toggle below.',
                'role_names' => [],
                'user_ids' => [],
                // Unlike the other events, a personal task (Task Manager
                // "My Tasks") has no assignee at all — notify_assignee alone
                // would silently never fire for it. The creator is always
                // who actually needs the due-date reminder, whether the
                // task is personal or team, so this one defaults to true
                // (status_changed/commented already default this way too;
                // only "assigned" doesn't, since that event is about the
                // newly-assigned person specifically).
                'notify_creator' => true,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
                'reminder_offset_hours' => 0,
            ],
        ];
    }

    /** The live rule for one event, auto-healing to defaults() if the row is somehow missing. */
    public static function ruleFor(int $accountId, string $eventKey): array
    {
        $row = static::where('account_id', $accountId)->where('event_key', $eventKey)->first();

        $default = self::defaults()[$eventKey] ?? null;

        if ($row) {
            return [
                'role_names' => $row->role_names ?? [],
                'user_ids' => $row->user_ids ?? [],
                'notify_creator' => (bool) $row->notify_creator,
                'notify_assignee' => (bool) $row->notify_assignee,
                'email_enabled' => (bool) $row->email_enabled,
                'sms_enabled' => (bool) $row->sms_enabled,
                'reminder_offset_hours' => $row->reminder_offset_hours ?? ($default['reminder_offset_hours'] ?? 0),
            ];
        }

        return $default
            ? ['role_names' => $default['role_names'], 'user_ids' => $default['user_ids'], 'notify_creator' => $default['notify_creator'], 'notify_assignee' => $default['notify_assignee'], 'email_enabled' => $default['email_enabled'], 'sms_enabled' => $default['sms_enabled'], 'reminder_offset_hours' => $default['reminder_offset_hours'] ?? 0]
            : ['role_names' => [], 'user_ids' => [], 'notify_creator' => false, 'notify_assignee' => false, 'email_enabled' => false, 'sms_enabled' => false, 'reminder_offset_hours' => 0];
    }

    private static function roleAndUserRecipients(array $rule, int $accountId, ?int $excludeUserId): Collection
    {
        $recipients = new Collection();

        if (! empty($rule['role_names'])) {
            $recipients = $recipients->merge(
                User::whereHas('roles', fn ($q) => $q->whereIn('name', $rule['role_names']))
                    ->where('account_id', $accountId)
                    ->where('is_active', true)
                    ->get()
            );
        }

        if (! empty($rule['user_ids'])) {
            $recipients = $recipients->merge(User::whereIn('id', $rule['user_ids'])->where('is_active', true)->get());
        }

        return $recipients->unique('id')->when($excludeUserId, fn ($c) => $c->reject(fn ($u) => (int) $u->id === (int) $excludeUserId));
    }

    /** Recipients for one specific newly-assigned person (the "this is now yours" notice), plus any configured roles/users/creator. */
    public static function recipientsForAssignment(int $accountId, Task $task, ?User $newAssignee, ?int $excludeUserId = null): Collection
    {
        $rule = self::ruleFor($accountId, self::EVENT_ASSIGNED);
        $recipients = self::roleAndUserRecipients($rule, $accountId, $excludeUserId);

        if ($rule['notify_assignee'] && $newAssignee) {
            $recipients->push($newAssignee);
        }

        if ($rule['notify_creator'] && $task->createdBy) {
            $recipients->push($task->createdBy);
        }

        return $recipients->unique('id')->values();
    }

    /** Recipients for a status change — loops every CURRENT assignee (Task Manager supports several), plus the creator and any configured roles/users. */
    public static function recipientsForStatusChange(int $accountId, Task $task, ?int $excludeUserId = null): Collection
    {
        $rule = self::ruleFor($accountId, self::EVENT_STATUS_CHANGED);
        $recipients = self::roleAndUserRecipients($rule, $accountId, $excludeUserId);

        if ($rule['notify_creator'] && $task->createdBy && (int) $task->createdBy->id !== (int) $excludeUserId) {
            $recipients->push($task->createdBy);
        }

        if ($rule['notify_assignee']) {
            foreach ($task->assignees as $taskAssignee) {
                if ($taskAssignee->assignee && (int) $taskAssignee->assignee->id !== (int) $excludeUserId) {
                    $recipients->push($taskAssignee->assignee);
                }
            }
        }

        return $recipients->unique('id')->values();
    }

    /** Recipients for a new comment — excludes the commenter from the creator/assignee "receipt" too, since being told about your own comment has no value. */
    public static function recipientsForComment(int $accountId, Task $task, ?int $excludeUserId = null): Collection
    {
        $rule = self::ruleFor($accountId, self::EVENT_COMMENTED);
        $recipients = self::roleAndUserRecipients($rule, $accountId, $excludeUserId);

        if ($rule['notify_creator'] && $task->createdBy && (int) $task->createdBy->id !== (int) $excludeUserId) {
            $recipients->push($task->createdBy);
        }

        if ($rule['notify_assignee']) {
            foreach ($task->assignees as $taskAssignee) {
                if ($taskAssignee->assignee && (int) $taskAssignee->assignee->id !== (int) $excludeUserId) {
                    $recipients->push($taskAssignee->assignee);
                }
            }
        }

        return $recipients->unique('id')->values();
    }

    /** Recipients for a due-date reminder — every current assignee plus the creator (if configured), no actor to exclude since nothing was clicked. */
    public static function recipientsForDueReminder(int $accountId, Task $task): Collection
    {
        $rule = self::ruleFor($accountId, self::EVENT_DUE_REMINDER);
        $recipients = self::roleAndUserRecipients($rule, $accountId, null);

        if ($rule['notify_creator'] && $task->createdBy) {
            $recipients->push($task->createdBy);
        }

        if ($rule['notify_assignee']) {
            foreach ($task->assignees as $taskAssignee) {
                if ($taskAssignee->assignee) {
                    $recipients->push($taskAssignee->assignee);
                }
            }
        }

        return $recipients->unique('id')->values();
    }

    /** For the admin UI — $onlyRoleNames restricts role checkboxes to real, currently-existing roles. */
    public static function forAccount(int $accountId, ?array $onlyRoleNames = null): array
    {
        $rows = static::where('account_id', $accountId)->get()->keyBy('event_key');
        $result = [];

        foreach (self::defaults() as $eventKey => $default) {
            $row = $rows->get($eventKey);
            $roleNames = $row ? ($row->role_names ?? []) : $default['role_names'];

            if ($onlyRoleNames !== null) {
                $roleNames = array_values(array_intersect($roleNames, $onlyRoleNames));
            }

            $result[$eventKey] = [
                'event_key' => $eventKey,
                'label' => $row->label ?? $default['label'],
                'description' => $row->description ?? $default['description'],
                'role_names' => $roleNames,
                'user_ids' => $row->user_ids ?? $default['user_ids'],
                'notify_creator' => $row ? (bool) $row->notify_creator : $default['notify_creator'],
                'notify_assignee' => $row ? (bool) $row->notify_assignee : $default['notify_assignee'],
                'email_enabled' => $row ? (bool) $row->email_enabled : $default['email_enabled'],
                'sms_enabled' => $row ? (bool) $row->sms_enabled : $default['sms_enabled'],
                'reminder_offset_hours' => $row ? ($row->reminder_offset_hours ?? ($default['reminder_offset_hours'] ?? 0)) : ($default['reminder_offset_hours'] ?? 0),
            ];
        }

        return $result;
    }
}
