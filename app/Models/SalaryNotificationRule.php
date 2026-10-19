<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Softcoded "who gets notified" for Salary — one row per event, mirroring
 * CaseTrackingNotificationRule exactly, but with a single notify_staff
 * toggle (the salaried employee themselves) instead of a creator/assignee
 * pair, since a salary record has one subject, not two actors.
 */
class SalaryNotificationRule extends Model
{
    protected $fillable = ['account_id', 'event_key', 'label', 'description', 'role_names', 'user_ids', 'notify_staff', 'email_enabled', 'sms_enabled'];

    protected $casts = [
        'role_names' => 'array',
        'user_ids' => 'array',
        'notify_staff' => 'boolean',
        'email_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
    ];

    public const EVENT_PREPARED = 'prepared';
    public const EVENT_SUBMITTED = 'submitted';
    public const EVENT_APPROVED = 'approved';
    public const EVENT_REJECTED = 'rejected';
    public const EVENT_PAID = 'paid';
    public const EVENT_PARTIALLY_PAID = 'partially_paid';

    public static function defaults(): array
    {
        return [
            self::EVENT_PREPARED => [
                'label' => 'Salary Prepared',
                'description' => 'Notified when a salary period is prepared.',
                'role_names' => ['Admin'], 'user_ids' => [], 'notify_staff' => false,
                'email_enabled' => true, 'sms_enabled' => false,
            ],
            self::EVENT_SUBMITTED => [
                'label' => 'Salary Submitted for Approval',
                'description' => 'Notified when a prepared salary period is submitted for approval.',
                'role_names' => ['Admin'], 'user_ids' => [], 'notify_staff' => false,
                'email_enabled' => true, 'sms_enabled' => false,
            ],
            self::EVENT_APPROVED => [
                'label' => 'Salary Approved',
                'description' => 'Notified when a salary period is approved and ready for payment.',
                'role_names' => ['Admin'], 'user_ids' => [], 'notify_staff' => false,
                'email_enabled' => true, 'sms_enabled' => false,
            ],
            self::EVENT_REJECTED => [
                'label' => 'Salary Rejected',
                'description' => 'Notified when a submitted salary period is rejected, with the reason.',
                'role_names' => ['Admin'], 'user_ids' => [], 'notify_staff' => false,
                'email_enabled' => true, 'sms_enabled' => false,
            ],
            self::EVENT_PAID => [
                'label' => 'Salary Paid',
                'description' => "The employee's own payment receipt, notified when their salary is paid in full.",
                'role_names' => [], 'user_ids' => [], 'notify_staff' => true,
                'email_enabled' => true, 'sms_enabled' => true,
            ],
            self::EVENT_PARTIALLY_PAID => [
                'label' => 'Partial Salary Payment Made',
                'description' => 'Notified when a partial salary payment is recorded, with the remaining balance.',
                'role_names' => [], 'user_ids' => [], 'notify_staff' => true,
                'email_enabled' => true, 'sms_enabled' => true,
            ],
        ];
    }

    public static function ruleFor(int $accountId, string $eventKey): array
    {
        $row = static::where('account_id', $accountId)->where('event_key', $eventKey)->first();

        if ($row) {
            return [
                'role_names' => $row->role_names ?? [],
                'user_ids' => $row->user_ids ?? [],
                'notify_staff' => (bool) $row->notify_staff,
                'email_enabled' => (bool) $row->email_enabled,
                'sms_enabled' => (bool) $row->sms_enabled,
            ];
        }

        $default = self::defaults()[$eventKey] ?? null;

        return $default
            ? ['role_names' => $default['role_names'], 'user_ids' => $default['user_ids'], 'notify_staff' => $default['notify_staff'], 'email_enabled' => $default['email_enabled'], 'sms_enabled' => $default['sms_enabled']]
            : ['role_names' => [], 'user_ids' => [], 'notify_staff' => false, 'email_enabled' => false, 'sms_enabled' => false];
    }

    /** Role/user-list recipients only (Users) — staff notification is handled separately by the caller since Staff isn't a User. */
    public static function recipientsFor(int $accountId, string $eventKey, ?int $excludeUserId = null): Collection
    {
        $rule = self::ruleFor($accountId, $eventKey);
        $recipients = new Collection();

        if (! empty($rule['role_names'])) {
            $recipients = $recipients->merge(
                User::whereHas('roles', fn ($q) => $q->whereIn('name', $rule['role_names']))
                    ->where('is_active', true)
                    ->get()
            );
        }

        if (! empty($rule['user_ids'])) {
            $recipients = $recipients->merge(User::whereIn('id', $rule['user_ids'])->where('is_active', true)->get());
        }

        return $recipients
            ->unique('id')
            ->when($excludeUserId, fn ($c) => $c->reject(fn ($u) => (int) $u->id === (int) $excludeUserId))
            ->values();
    }

    public static function forAccount(int $accountId): array
    {
        $rows = static::where('account_id', $accountId)->get()->keyBy('event_key');
        $result = [];

        foreach (self::defaults() as $eventKey => $default) {
            $row = $rows->get($eventKey);
            $result[$eventKey] = [
                'event_key' => $eventKey,
                'label' => $row->label ?? $default['label'],
                'description' => $row->description ?? $default['description'],
                'role_names' => $row->role_names ?? $default['role_names'],
                'user_ids' => $row->user_ids ?? $default['user_ids'],
                'notify_staff' => $row ? (bool) $row->notify_staff : $default['notify_staff'],
                'email_enabled' => $row ? (bool) $row->email_enabled : $default['email_enabled'],
                'sms_enabled' => $row ? (bool) $row->sms_enabled : $default['sms_enabled'],
            ];
        }

        return $result;
    }
}
