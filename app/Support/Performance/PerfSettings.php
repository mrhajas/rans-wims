<?php

namespace App\Support\Performance;

use App\Models\Setting;

/**
 * The soft-coded numbers of Settings > Performance & Data. Every one is stored in the settings table (key "perf_...") and can be changed there by a Super Admin;
 * the defaults below are used until somebody changes them.
 */
class PerfSettings
{
    public const DEFAULTS = [
        'perf_activity_keep_months' => 12,      // activity history older than this many months moves to the activity archive (0 = never move it)
        'perf_notification_keep_days' => 180,   // read bell alerts older than this are removed (0 = keep forever)
        'perf_weather_keep_days' => 30,         // weather readings older than this are removed (0 = keep forever)
        'perf_log_keep_days' => 14,             // server log files older than this are removed (0 = keep forever)
        'perf_slow_keep_days' => 30,            // slow query / slow page records older than this are removed
        'perf_slow_query_ms' => 300,            // a database query slower than this is recorded (0 = off)
        'perf_slow_request_ms' => 1500,         // a page slower than this is recorded (0 = off)
        'perf_queue_background' => 0,           // 1 = send SMS, e-mail and bell alerts in the background after the save
    ];

    /** The allowed range of each number (min, max). */
    public const LIMITS = [
        'perf_activity_keep_months' => [0, 120],
        'perf_notification_keep_days' => [0, 3650],
        'perf_weather_keep_days' => [0, 3650],
        'perf_log_keep_days' => [0, 365],
        'perf_slow_keep_days' => [1, 365],
        'perf_slow_query_ms' => [0, 60000],
        'perf_slow_request_ms' => [0, 120000],
        'perf_queue_background' => [0, 1],
    ];

    public static function get(string $key): int
    {
        $value = null;
        try {
            $value = get_settings($key);
        } catch (\Throwable $e) {
            // the settings table is not there yet (a fresh install or a migration is running): use the default
        }
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return (int) self::DEFAULTS[$key];
        }
        [$min, $max] = self::LIMITS[$key];

        return max($min, min($max, (int) $value));
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $out[$key] = self::get($key);
        }

        return $out;
    }

    public static function save(array $values, int $accountId): array
    {
        $saved = [];
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            [$min, $max] = self::LIMITS[$key];
            $saved[$key] = max($min, min($max, (int) $value));
            Setting::updateOrCreate(['tec_key' => $key, 'account_id' => $accountId], ['tec_value' => (string) $saved[$key]]);
        }

        return $saved;
    }
}
