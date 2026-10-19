<?php

namespace App\Services\Weather;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Weather & Site Risk settings, one JSON setting per account (tec_key 'weather_risk'). The provider API key lives in the same JSON,
 * ENCRYPTED with the application key (Crypt); it is never returned to a browser (only "set" and its last four characters), never written
 * to a log or an activity-log property. When no key is saved the deployment default (WEATHER_API_KEY through config/weather.php) is used.
 */
class WeatherSettings
{
    public const KEY = 'weather_risk';

    public const DEFAULTS = [
        'provider' => 'none',            // none | open_meteo | openweathermap | openweathermap_free
        'base_url' => '',                // optional address override (https only); empty = the provider's standard address
        'units' => 'metric',             // fixed: km/h, mm, degrees Celsius (every threshold and report uses these)
        'ttl_current_min' => 30,
        'ttl_forecast_min' => 180,
        'ttl_alerts_min' => 30,
        'stale_multiplier' => 3,         // data older than TTL x this is called stale
        'manual_refresh_min' => 5,       // the Refresh button: once per location in this many minutes
        'failure_backoff_min' => 10,     // after a failed call, do not call again for this long (scheduled and on-view calls)
        'refresh_on_view' => true,       // fetch an expired location when somebody opens the page (the scheduler does it too)
        'forecast_hours' => 72,
        'timeout_seconds' => 8,
        'retries' => 1,
        'warn_without_scheduled_work' => false,
        'reopen_increase_pct' => 25,     // an acknowledged warning is re-opened when the forecast peak is this much worse
        'snapshot_retention_days' => 45,
        'observation_edit_hours' => 24,
        'decisions' => ['No change to the plan', 'Precautions taken', 'Work rescheduled', 'Work stopped by the site team'],
        // the key (never sent to a browser)
        'api_key_enc' => null, 'api_key_last4' => null, 'api_key_set_at' => null, 'api_key_set_by' => null,
        // the last "Test connection"
        'last_test' => null,
    ];

    /** Settings the Settings page may change (everything except the key and the units). */
    public const EDITABLE = [
        'provider', 'base_url', 'ttl_current_min', 'ttl_forecast_min', 'ttl_alerts_min', 'stale_multiplier', 'manual_refresh_min', 'failure_backoff_min',
        'refresh_on_view', 'forecast_hours', 'timeout_seconds', 'retries', 'warn_without_scheduled_work', 'reopen_increase_pct', 'snapshot_retention_days',
        'observation_edit_hours', 'decisions',
    ];

    /** The settings are read many times while one page is built (once per project card): kept for a few seconds, and dropped at every save. */
    private static array $memo = [];

    public static function get(?int $accountId = null): array
    {
        $accountId ??= (int) (auth()->user()->account_id ?? 1);
        if (isset(self::$memo[$accountId]) && microtime(true) - self::$memo[$accountId][0] < 5) {
            return self::$memo[$accountId][1];
        }
        $raw = Setting::query()->where('tec_key', self::KEY)->where('account_id', $accountId)->value('tec_value');
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $out = array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        if (($saved['provider'] ?? null) === null && config('weather.provider') && isset(config('weather.providers')[config('weather.provider')])) {
            $out['provider'] = config('weather.provider'); // a deployment default, used only until the Settings page saves something
        }
        self::$memo[$accountId] = [microtime(true), $out];

        return $out;
    }

    /** What may be sent to a browser: everything except the encrypted key. */
    public static function publicView(?int $accountId = null): array
    {
        $s = self::get($accountId);
        unset($s['api_key_enc']);
        $s['key'] = self::keySummary($accountId);
        $s['units'] = 'metric';

        return $s;
    }

    public static function save(array $data, ?int $accountId = null): array
    {
        $accountId ??= (int) (auth()->user()->account_id ?? 1);
        unset(self::$memo[$accountId]);
        $current = self::get($accountId);
        $clean = array_replace($current, array_intersect_key($data, self::DEFAULTS));
        Setting::query()->updateOrCreate(['tec_key' => self::KEY, 'account_id' => $accountId], ['tec_value' => json_encode($clean)]);
        unset(self::$memo[$accountId]);

        return $clean;
    }

    /** The decrypted key, or the deployment default, or null. Only for building a provider request. */
    public static function apiKey(?int $accountId = null): ?string
    {
        $enc = self::get($accountId)['api_key_enc'] ?? null;
        if ($enc) {
            try {
                $plain = Crypt::decryptString($enc);

                return $plain !== '' ? $plain : null;
            } catch (\Throwable) {
                return null; // an unreadable key (application key changed) counts as "not set"
            }
        }
        $env = config('weather.api_key');

        return is_string($env) && $env !== '' ? $env : null;
    }

    public static function keySummary(?int $accountId = null): array
    {
        $s = self::get($accountId);
        if (! empty($s['api_key_enc'])) {
            return ['set' => true, 'source' => 'settings', 'last4' => $s['api_key_last4'], 'set_at' => $s['api_key_set_at']];
        }
        $env = config('weather.api_key');
        if (is_string($env) && $env !== '') {
            return ['set' => true, 'source' => 'environment', 'last4' => substr($env, -4), 'set_at' => null];
        }

        return ['set' => false, 'source' => null, 'last4' => null, 'set_at' => null];
    }

    public static function setKey(string $plain, ?int $userId, ?int $accountId = null): void
    {
        $plain = trim($plain);
        self::save([
            'api_key_enc' => Crypt::encryptString($plain), 'api_key_last4' => substr($plain, -4),
            'api_key_set_at' => now()->toDateTimeString(), 'api_key_set_by' => $userId,
        ], $accountId);
    }

    public static function removeKey(?int $accountId = null): void
    {
        self::save(['api_key_enc' => null, 'api_key_last4' => null, 'api_key_set_at' => null, 'api_key_set_by' => null], $accountId);
    }

    /** Wind, rain and temperature option lists used by the observation form (soft-coded in one place). */
    public static function options(): array
    {
        return [
            'conditions' => [
                'clear' => 'Clear', 'partly_cloudy' => 'Partly cloudy', 'cloudy' => 'Cloudy', 'light_rain' => 'Light rain', 'heavy_rain' => 'Heavy rain',
                'storm' => 'Thunderstorm', 'fog' => 'Fog or mist', 'dust' => 'Dust or haze', 'hot' => 'Very hot', 'cold' => 'Cold',
            ],
            'rainfall' => ['none' => 'No rain', 'drizzle' => 'Drizzle', 'light' => 'Light rain', 'moderate' => 'Moderate rain', 'heavy' => 'Heavy rain'],
            'wind' => ['calm' => 'Calm', 'breezy' => 'Breezy', 'strong' => 'Strong wind', 'very_strong' => 'Very strong wind'],
        ];
    }
}
