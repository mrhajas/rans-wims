<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** Who hears about Site Diary events. A missing row behaves as the default: Super Admins, bell only, switched on. */
class SiteDiaryNotificationRule extends Eloquent
{
    public const NEW_DIARY = 'NEW_DIARY';

    public const NEW_ASSIGNED_PROJECT = 'NEW_ASSIGNED_PROJECT';

    public const NEW_PHOTOS = 'NEW_PHOTOS';

    public const NEW_VIDEOS = 'NEW_VIDEOS';

    public const MISSING_DIARY = 'MISSING_DIARY';

    public const DIARY_UPDATED = 'DIARY_UPDATED';

    public const DIARY_DELETED = 'DIARY_DELETED';

    public const DIARY_RESTORED = 'DIARY_RESTORED';

    public const MEDIA_REMOVED = 'MEDIA_REMOVED';

    public const DRIVE_UPLOAD_FAILED = 'DRIVE_UPLOAD_FAILED';

    public const SETTINGS_CHANGED = 'SETTINGS_CHANGED';

    protected $table = 'site_diary_notification_rules';

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

    public static function defaults(): array
    {
        $base = ['enabled' => true, 'notify_bell' => true, 'send_email' => false, 'role_names' => ['Super Admin'], 'user_ids' => [], 'exclude_actor' => true, 'send_time' => null, 'last_run_at' => null];

        return [
            self::NEW_DIARY => $base + ['label' => __('New Site Diary created'), 'description' => __('A diary was recorded for any project.'), 'kind' => 'instant'],
            self::NEW_ASSIGNED_PROJECT => ['enabled' => true, 'role_names' => ['Project Manager', 'Project Managers']] + $base + ['label' => __('New Site Diary for an assigned project'), 'description' => __('Only the people assigned to that project, within the roles chosen here.'), 'kind' => 'instant'],
            self::NEW_PHOTOS => ['enabled' => false] + $base + ['label' => __('New photos added'), 'description' => __('Photos were added to a diary.'), 'kind' => 'instant'],
            self::NEW_VIDEOS => ['enabled' => false] + $base + ['label' => __('New videos added'), 'description' => __('Videos were added to a diary.'), 'kind' => 'instant'],
            self::MISSING_DIARY => ['enabled' => false, 'send_time' => '17:30'] + $base + ['label' => __('Site Diary missing for the day'), 'description' => __('Projects that require a daily diary and have none for today, sent once a day at the time set here.'), 'kind' => 'scheduled'],
        ];
    }

    public static function forAccount($accountId): array
    {
        $stored = static::where('account_id', $accountId)->get()->keyBy('event_key');
        $out = [];
        foreach (self::defaults() as $key => $def) {
            $row = $stored->get($key);
            $out[$key] = array_merge($def, ['event_key' => $key], $row ? [
                'enabled' => (bool) $row->enabled,
                'notify_bell' => (bool) $row->notify_bell,
                'send_email' => (bool) $row->send_email,
                'role_names' => $row->role_names ?? [],
                'user_ids' => $row->user_ids ?? [],
                'exclude_actor' => (bool) $row->exclude_actor,
                'send_time' => $row->send_time ?: $def['send_time'],
                'last_run_at' => $row->last_run_at,
            ] : []);
        }

        return $out;
    }

    public static function ruleFor($accountId, string $key): array
    {
        return static::forAccount($accountId)[$key];
    }
}
