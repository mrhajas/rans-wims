<?php

namespace App\Support\Optimizer;

use App\Models\Setting;

/** The numbers of Settings > Optimize. Each is kept in the settings table (key "opt_...") and can be changed on that page; the defaults are used until then. */
class OptimizerSettings
{
    public const DEFAULTS = [
        'opt_image_max_px' => 1600,          // a picture wider or taller than this is scaled down to it
        'opt_image_quality' => 80,           // JPEG / WebP quality used when a picture is saved again (1-100)
        'opt_image_min_kb' => 250,           // a picture smaller than this (and not oversize) is left alone
        'opt_keep_originals_days' => 14,     // the untouched originals of changed pictures are kept this long (0 = keep no copy)
        'opt_log_gzip_days' => 2,            // server log files older than this are compressed
        'opt_seconds' => 90,                 // one run stops starting new work after this many seconds (run again to continue)
    ];

    /** @var array<string, array{0:int,1:int}> */
    public const LIMITS = [
        'opt_image_max_px' => [800, 6000],
        'opt_image_quality' => [40, 95],
        'opt_image_min_kb' => [50, 5000],
        'opt_keep_originals_days' => [0, 365],
        'opt_log_gzip_days' => [1, 60],
        'opt_seconds' => [20, 600],
    ];

    public static function get(string $key): int
    {
        $value = null;
        try {
            $value = get_settings($key);
        } catch (\Throwable $e) {
            // no settings table yet: the default
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
