<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Who is told about which Archive event, and when" — one row per event. Super Admin edits them on the Archive
 * Notifications page. A missing row behaves exactly like the default below: Super Admins only, bell on, email off.
 *
 * kind: instant = sent the moment it happens; threshold = sent once a limit is reached; scheduled = sent once a day at send_time.
 */
class ArchiveNotificationRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'notify_bell' => 'boolean',
        'send_email' => 'boolean',
        'exclude_actor' => 'boolean',
        'role_names' => 'array',
        'user_ids' => 'array',
        'last_run_at' => 'datetime',
    ];

    public const RESTRICTED_RECORD_CREATED = 'restricted_record_created';
    public const RECORD_CREATED = 'record_created';
    public const CONFIDENTIALITY_CHANGED = 'confidentiality_changed';
    public const RESTRICTED_DOWNLOADED = 'restricted_downloaded';
    public const RESTRICTED_OPENED = 'restricted_opened';
    public const RECORD_DELETED = 'record_deleted';
    public const FOLDER_UNLOCKED = 'folder_unlocked';
    public const FAILED_UNLOCK = 'failed_unlock';
    public const EXPIRY_DIGEST = 'expiry_digest';
    public const RECORD_UPDATED = 'record_updated';
    public const RECORD_RESTORED = 'record_restored';
    public const FILE_ADDED = 'file_added';
    public const FILE_REMOVED = 'file_removed';
    public const SCHEDULE_CHANGED = 'schedule_changed';

    public static function defaults(): array
    {
        $row = fn (string $label, string $description, string $kind, bool $enabled, string $icon, array $extra = []) => array_merge([
            'label' => $label, 'description' => $description, 'kind' => $kind, 'icon' => $icon,
            'enabled' => $enabled, 'notify_bell' => true, 'send_email' => false,
            'role_names' => ['Super Admin'], 'user_ids' => [], 'exclude_actor' => true,
            'send_time' => null, 'lead_days' => null,
        ], $extra);

        return [
            self::RESTRICTED_RECORD_CREATED => $row('Confidential record created', 'A Confidential or Restricted record is added to the Archive.', 'instant', true, 'lock-closed'),
            self::RECORD_CREATED => $row('Any record created', 'Every new Archive record, whatever its confidentiality. Can be noisy.', 'instant', false, 'plus'),
            self::CONFIDENTIALITY_CHANGED => $row('Confidentiality changed', 'A record is made more or less confidential.', 'instant', true, 'adjustments'),
            self::RESTRICTED_DOWNLOADED => $row('Confidential document downloaded', 'Someone downloads a file from a Confidential or Restricted record.', 'instant', true, 'download'),
            self::RESTRICTED_OPENED => $row('Confidential document opened', 'Someone previews or opens (new tab) a file from a Confidential or Restricted record.', 'instant', false, 'eye'),
            self::RECORD_DELETED => $row('Record deleted', 'An Archive record is deleted (it can still be restored).', 'instant', true, 'trash'),
            self::FOLDER_UNLOCKED => $row('Restricted folder unlocked', 'A user enters their password to open a restricted folder.', 'instant', false, 'lock-open'),
            self::FAILED_UNLOCK => $row('Failed unlock attempts', 'A user gets their password wrong 3 times within 15 minutes when opening restricted content.', 'threshold', true, 'exclamation'),
            self::EXPIRY_DIGEST => $row('Expiring / expired documents', 'A daily summary of documents that are about to expire or have expired.', 'scheduled', true, 'clock', ['send_time' => '08:00', 'lead_days' => 30]),
        ];
    }

    /** Every rule for an account, stored rows merged over the defaults, in display order. */
    public static function forAccount(?int $accountId): array
    {
        $stored = static::query()->where('account_id', $accountId)->get()->keyBy('event_key');
        $out = [];

        foreach (self::defaults() as $key => $default) {
            $row = $stored->get($key);
            $out[$key] = ['event_key' => $key] + ($row ? array_merge($default, [
                'enabled' => (bool) $row->enabled,
                'notify_bell' => (bool) $row->notify_bell,
                'send_email' => (bool) $row->send_email,
                'role_names' => $row->role_names ?? [],
                'user_ids' => $row->user_ids ?? [],
                'exclude_actor' => (bool) $row->exclude_actor,
                'send_time' => $row->send_time ?: $default['send_time'],
                'lead_days' => $row->lead_days ?? $default['lead_days'],
                'last_run_at' => $row->last_run_at,
            ]) : $default + ['last_run_at' => null]);
        }

        return $out;
    }

    public static function ruleFor(?int $accountId, string $eventKey): ?array
    {
        return self::forAccount($accountId)[$eventKey] ?? null;
    }
}
