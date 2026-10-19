<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Softcoded "who gets notified" for Case Tracking — one row per event, each
 * with a role/user list plus two special-case toggles: notify_creator and
 * notify_assignee (Case Tracking has both a creator AND an assignee, unlike
 * Material Request's single "requester" concept — see
 * MaterialRequestNotificationRule, which this mirrors exactly otherwise).
 * Super Admin reassigns each event's audience from Case Tracking →
 * Categories & Priorities → Notifications.
 */
class CaseTrackingNotificationRule extends Model
{
    protected $fillable = ['account_id', 'event_key', 'label', 'description', 'role_names', 'user_ids', 'notify_creator', 'notify_assignee', 'email_enabled', 'sms_enabled'];

    protected $casts = [
        'role_names' => 'array',
        'user_ids' => 'array',
        'notify_creator' => 'boolean',
        'notify_assignee' => 'boolean',
        'email_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
    ];

    public const EVENT_SUBMITTED = 'submitted';
    public const EVENT_IN_REVIEW = 'in_review';
    public const EVENT_IN_PROGRESS = 'in_progress';
    public const EVENT_RETURNED = 'returned';
    public const EVENT_RESOLVED = 'resolved';
    public const EVENT_REOPENED = 'reopened';
    public const EVENT_CLOSED = 'closed';
    public const EVENT_ASSIGNED = 'assigned';
    public const EVENT_COMMENTED = 'commented';

    /** Today's hardcoded audiences, reproduced exactly — nothing changes for anyone until a Super Admin edits a rule. */
    public static function defaults(): array
    {
        return [
            self::EVENT_SUBMITTED => [
                'label' => 'Case Submitted',
                'description' => 'Notified when a case is first submitted for review, the "someone needs to pick this up" notice.',
                'role_names' => ['Super Admin', 'Admin'],
                'user_ids' => [],
                'notify_creator' => false,
                'notify_assignee' => false,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_IN_REVIEW => [
                'label' => 'Case In Review',
                'description' => 'Notified when a case moves into review.',
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => true,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_IN_PROGRESS => [
                'label' => 'Case In Progress',
                'description' => 'Notified when work starts on a case.',
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => true,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_RETURNED => [
                'label' => 'Case Returned for Correction',
                'description' => 'Notified when a case is sent back to the creator for more information.',
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => true,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_RESOLVED => [
                'label' => 'Case Resolved',
                'description' => 'Notified when a resolution is recorded.',
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => true,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_REOPENED => [
                'label' => 'Case Reopened',
                'description' => 'Notified when a Resolved/Closed case is reopened.',
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => true,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_CLOSED => [
                'label' => 'Case Closed',
                'description' => 'Notified when a case is finally closed.',
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => true,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_ASSIGNED => [
                'label' => 'Case Assigned / Reassigned',
                'description' => "The new assignee's own \"this is now yours\" notice.",
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => false,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
            self::EVENT_COMMENTED => [
                'label' => 'Comment Added',
                'description' => 'Notified when someone comments on a case.',
                'role_names' => [],
                'user_ids' => [],
                'notify_creator' => true,
                'notify_assignee' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
            ],
        ];
    }

    /** The live rule for one event, auto-healing to defaults() if the row is somehow missing. */
    public static function ruleFor(int $accountId, string $eventKey): array
    {
        $row = static::where('account_id', $accountId)->where('event_key', $eventKey)->first();

        if ($row) {
            return [
                'role_names' => $row->role_names ?? [],
                'user_ids' => $row->user_ids ?? [],
                'notify_creator' => (bool) $row->notify_creator,
                'notify_assignee' => (bool) $row->notify_assignee,
                'email_enabled' => (bool) $row->email_enabled,
                'sms_enabled' => (bool) $row->sms_enabled,
            ];
        }

        $default = self::defaults()[$eventKey] ?? null;

        return $default
            ? ['role_names' => $default['role_names'], 'user_ids' => $default['user_ids'], 'notify_creator' => $default['notify_creator'], 'notify_assignee' => $default['notify_assignee'], 'email_enabled' => $default['email_enabled'], 'sms_enabled' => $default['sms_enabled']]
            : ['role_names' => [], 'user_ids' => [], 'notify_creator' => false, 'notify_assignee' => false, 'email_enabled' => false, 'sms_enabled' => false];
    }

    /**
     * Every recipient for $eventKey on $case — role_names + user_ids resolved
     * to active User rows (minus $excludeUserId, so the acting user doesn't
     * hear about their own action via role membership), PLUS the creator
     * and/or assignee if their flags are on.
     *
     * $excludeUserId is applied ONLY to the role/user_ids audience, never to
     * the creator/assignee entries — matching MaterialRequestNotificationRule
     * exactly: those notices must always fire when their flag is on, even
     * when the creator/assignee happens to be the one who acted (e.g. a
     * Super Admin resolving their own case still gets their own "Resolved"
     * receipt). EVENT_COMMENTED is the one exception: there's no "receipt"
     * value in being told about your own comment, so the creator/assignee
     * push is also excluded for that event specifically.
     */
    public static function recipientsFor(int $accountId, string $eventKey, CaseTicket $case, ?int $excludeUserId = null): Collection
    {
        $rule = self::ruleFor($accountId, $eventKey);
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
            $recipients = $recipients->merge(
                User::whereIn('id', $rule['user_ids'])->where('is_active', true)->get()
            );
        }

        $recipients = $recipients
            ->unique('id')
            ->when($excludeUserId, fn ($c) => $c->reject(fn ($u) => (int) $u->id === (int) $excludeUserId));

        $excludeFromReceipt = $eventKey === self::EVENT_COMMENTED ? $excludeUserId : null;

        if ($rule['notify_creator'] && $case->createdBy && (int) $case->createdBy->id !== (int) $excludeFromReceipt) {
            $recipients->push($case->createdBy);
        }

        if ($rule['notify_assignee'] && $case->assignedTo && (int) $case->assignedTo->id !== (int) $excludeFromReceipt) {
            $recipients->push($case->assignedTo);
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
            ];
        }

        return $result;
    }
}
