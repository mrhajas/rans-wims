<?php

namespace App\Support\Backup;

use App\Models\BackupActivity;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * The automatic database backup: how often it runs, whether it runs, and how many of the automatic backups are kept. Stored in the settings
 * table (Backup page, Super Admin) so nothing is fixed in the code. The shortest interval is 8 hours, the longest 15 days.
 */
class AutoBackupSettings
{
    public const MIN_HOURS = 8;
    public const MAX_HOURS = 360; // 15 days

    public const DEFAULTS = ['auto_backup_enabled' => 1, 'auto_backup_interval_hours' => 24, 'auto_backup_keep' => 3];

    /** The hours a person can choose: 8, 16, 24, then whole days from 2 to 15. */
    public static function options(): array
    {
        return array_merge([8, 16, 24], array_map(fn ($d) => $d * 24, range(2, 15)));
    }

    private static function raw(string $key)
    {
        try {
            $v = Setting::query()->where('tec_key', $key)->orderBy('id')->value('tec_value');
        } catch (\Throwable $e) {
            $v = null;
        }

        return ($v === null || $v === '') ? self::DEFAULTS[$key] : $v;
    }

    public static function enabled(): bool
    {
        return (int) self::raw('auto_backup_enabled') === 1;
    }

    public static function intervalHours(): int
    {
        $h = (int) self::raw('auto_backup_interval_hours');

        return in_array($h, self::options(), true) ? $h : self::DEFAULTS['auto_backup_interval_hours'];
    }

    public static function keep(): int
    {
        return max(1, min(30, (int) self::raw('auto_backup_keep')));
    }

    public static function save(bool $enabled, int $hours, int $keep, ?int $accountId): void
    {
        $values = ['auto_backup_enabled' => $enabled ? 1 : 0, 'auto_backup_interval_hours' => $hours, 'auto_backup_keep' => $keep];
        foreach ($values as $key => $value) {
            $row = Setting::query()->where('tec_key', $key)->orderBy('id')->first();
            if ($row) {
                $row->update(['tec_value' => (string) $value]);
            } else {
                Setting::create(['tec_key' => $key, 'tec_value' => (string) $value, 'account_id' => $accountId]);
            }
        }
    }

    public static function lastAutoAt(): ?CarbonImmutable
    {
        $at = BackupActivity::query()->where('activity', 'Auto Back Up')->max('created_at');

        return $at ? CarbonImmutable::parse($at) : null;
    }

    public static function nextDueAt(): ?CarbonImmutable
    {
        if (! self::enabled()) {
            return null;
        }
        $last = self::lastAutoAt();

        return $last ? $last->addHours(self::intervalHours()) : CarbonImmutable::now();
    }

    /** Due when the interval has passed since the last automatic backup (10 minutes of tolerance, because the check runs once an hour). */
    public static function isDue(?CarbonImmutable $now = null): bool
    {
        if (! self::enabled()) {
            return false;
        }
        $now ??= CarbonImmutable::now();
        $last = self::lastAutoAt();

        return ! $last || $now >= $last->addHours(self::intervalHours())->subMinutes(10);
    }
}
