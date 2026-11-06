<?php

namespace App\Support\WimsLogs;

use App\Models\Setting;

/**
 * The numbers of Settings > WIMS Logs. Each is kept in the settings table (key "wimslogs_...") and can be changed on that page: whether it runs by itself, how many days
 * pass between runs, whether the tables are compacted afterwards, how many seconds one step may take, and for each log whether it is archived and how many days it stays live.
 */
class WimsLogSettings
{
    public const DEFAULTS = [
        'wimslogs_enabled' => 1,        // 1 = it runs by itself (the nightly scheduler), 0 = only when a Super Admin runs it
        'wimslogs_every_days' => 5,     // days between two runs by itself
        'wimslogs_optimize' => 1,       // 1 = compact the tables after a run, so the space is really given back
        'wimslogs_seconds' => 40,       // one step of a run stops starting new work after this many seconds (the screen asks again)
    ];

    /** @var array<string, array{0:int,1:int}> */
    public const LIMITS = [
        'wimslogs_enabled' => [0, 1],
        'wimslogs_every_days' => [1, 60],
        'wimslogs_optimize' => [0, 1],
        'wimslogs_seconds' => [10, 300],
    ];

    public const KEEP_MIN = 1;
    public const KEEP_MAX = 3650;

    public static function keepKey(string $source): string
    {
        return 'wimslogs_keep_' . $source;
    }

    public static function onKey(string $source): string
    {
        return 'wimslogs_on_' . $source;
    }

    private static function raw(string $key): ?string
    {
        try {
            $v = get_settings($key);
        } catch (\Throwable $e) {
            return null;
        }

        return ($v === null || $v === '') ? null : (string) $v;
    }

    public static function get(string $key): int
    {
        $v = self::raw($key);
        if ($v === null || ! is_numeric($v)) {
            return (int) self::DEFAULTS[$key];
        }
        [$min, $max] = self::LIMITS[$key];

        return max($min, min($max, (int) $v));
    }

    public static function keepDays(string $source): int
    {
        $default = (int) (Sources::get($source)['keep_days'] ?? 30);
        $v = self::raw(self::keepKey($source));

        return ($v === null || ! is_numeric($v)) ? $default : max(self::KEEP_MIN, min(self::KEEP_MAX, (int) $v));
    }

    public static function isOn(string $source): bool
    {
        $v = self::raw(self::onKey($source));

        return $v === null ? true : $v !== '0';
    }

    /** @return array{general: array<string,int>, sources: array<string, array{on: bool, keep_days: int}>} */
    public static function all(): array
    {
        $general = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $general[$key] = self::get($key);
        }
        $sources = [];
        foreach (Sources::keys() as $k) {
            $sources[$k] = ['on' => self::isOn($k), 'keep_days' => self::keepDays($k)];
        }

        return ['general' => $general, 'sources' => $sources];
    }

    /** @return array{general: array<string,int>, sources: array<string, array{on: bool, keep_days: int}>} what was saved (every value inside its limits) */
    public static function save(array $general, array $sources, int $accountId): array
    {
        foreach ($general as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            [$min, $max] = self::LIMITS[$key];
            Setting::updateOrCreate(['tec_key' => $key, 'account_id' => $accountId], ['tec_value' => (string) max($min, min($max, (int) $value))]);
        }
        foreach ($sources as $key => $row) {
            if (! Sources::get($key)) {
                continue;
            }
            if (isset($row['keep_days'])) {
                Setting::updateOrCreate(['tec_key' => self::keepKey($key), 'account_id' => $accountId], ['tec_value' => (string) max(self::KEEP_MIN, min(self::KEEP_MAX, (int) $row['keep_days']))]);
            }
            if (array_key_exists('on', $row)) {
                Setting::updateOrCreate(['tec_key' => self::onKey($key), 'account_id' => $accountId], ['tec_value' => $row['on'] ? '1' : '0']);
            }
        }

        return self::all();
    }
}
