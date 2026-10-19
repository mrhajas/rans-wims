<?php

namespace App\Services\Weather;

use App\Models\User;
use App\Models\Warehouse;
use App\Models\WeatherSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The only place that talks to a weather provider and the only place that decides whether to. Everything else reads the saved snapshots.
 *
 *  - Coordinates are rounded to 2 decimals (about 1 km), so projects that round to the same place share ONE call.
 *  - One provider call answers current + forecast + alerts and is saved as up to three snapshot rows with their own expiry (TTLs are settings).
 *  - A page that only DISPLAYS never calls the provider. The scheduler refreshes a location when its forecast (or its alerts) has expired; a
 *    person opening the page refreshes it through the card's own request when anything shown is out of date; the Refresh button is limited
 *    to once per location per few minutes. After a failed call nothing is tried again for a few minutes.
 *  - State is always honest: ok | stale | failed | not_configured | no_location | no_data, with the timestamp and provider of what is shown.
 */
class WeatherManager
{
    public static function roundCoord(float $v): float
    {
        return round($v, 2);
    }

    public static function locationKey(float $lat, float $lng): string
    {
        return sprintf('%.2f,%.2f', round($lat, 2), round($lng, 2));
    }

    public function prefs(int $accountId): array
    {
        return WeatherSettings::get($accountId);
    }

    /** @return array{ok:bool, message:string, provider:?string, label:?string, supports_alerts:bool} */
    public function configuration(int $accountId): array
    {
        $prefs = $this->prefs($accountId);
        $key = $prefs['provider'];
        $cfg = config('weather.providers')[$key] ?? null;
        if (! $cfg) {
            return ['ok' => false, 'message' => 'No weather provider has been chosen yet. A person with the Weather Settings ability must choose one and enter its key.', 'provider' => null, 'label' => null, 'supports_alerts' => false];
        }
        if ($cfg['requires_key'] && ! WeatherSettings::apiKey($accountId)) {
            return ['ok' => false, 'message' => $cfg['label'] . ' needs a key. A person with the Weather Settings ability must add it.', 'provider' => $key, 'label' => $cfg['label'], 'supports_alerts' => (bool) $cfg['supports_alerts']];
        }

        return ['ok' => true, 'message' => '', 'provider' => $key, 'label' => $cfg['label'], 'supports_alerts' => (bool) $cfg['supports_alerts']];
    }

    public function provider(int $accountId): ?WeatherProvider
    {
        $conf = $this->configuration($accountId);
        if (! $conf['ok']) {
            return null;
        }
        $class = config('weather.providers')[$conf['provider']]['class'];

        return new $class($this->prefs($accountId), WeatherSettings::apiKey($accountId));
    }

    /** A project's coordinates, or null when either is missing or out of range. */
    public static function coordinates(Warehouse $w): ?array
    {
        if ($w->latitude === null || $w->longitude === null) {
            return null;
        }
        $lat = (float) $w->latitude;
        $lng = (float) $w->longitude;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return null;
        }

        return ['lat' => self::roundCoord($lat), 'lng' => self::roundCoord($lng), 'key' => self::locationKey($lat, $lng)];
    }

    // ------------------------------------------------------------------------------------------------------------------ reading

    /** What WIMS knows about a project's weather right now, from saved snapshots only (no provider call). */
    public function state(Warehouse $w): array
    {
        $accountId = (int) ($w->account_id ?: 1);
        $conf = $this->configuration($accountId);
        $loc = self::coordinates($w);
        $base = [
            'status' => 'ok', 'message' => '', 'provider' => $conf['provider'], 'provider_label' => $conf['label'], 'supports_alerts' => $conf['supports_alerts'],
            'location' => $loc, 'fetched_at' => null, 'age_minutes' => null, 'stale' => false, 'due' => false, 'current' => null, 'forecast' => null, 'alerts' => null,
            'current_at' => null, 'current_due' => false, 'alerts_at' => null, 'snapshot_ids' => [], 'last_error' => null, 'retry_after' => null, 'uncertainty' => null,
        ];
        if (! $loc) {
            return ['status' => 'no_location', 'message' => 'This project has no latitude and longitude yet, so no weather can be shown for it.'] + $base;
        }
        if (! $conf['ok']) {
            return ['status' => 'not_configured', 'message' => $conf['message']] + $base;
        }

        $prefs = $this->prefs($accountId);
        $rows = WeatherSnapshot::query()->where('account_id', $accountId)->where('location_key', $loc['key'])->where('provider', $conf['provider'])
            ->where('fetched_at', '>=', now()->subDays(3))->orderByDesc('fetched_at')->orderByDesc('id')->get();
        $latest = fn (string $kind) => $rows->firstWhere('kind', $kind);
        $latestOk = fn (string $kind) => $rows->first(fn ($r) => $r->kind === $kind && $r->status === 'ok');

        $fc = $latestOk('forecast');
        $cur = $latestOk('current');
        $al = $latestOk('alerts');
        $attempt = $latest('forecast'); // a failed call is saved as a failed forecast row
        $failed = $attempt && $attempt->status === 'failed' && (! $fc || $attempt->fetched_at->gt($fc->fetched_at));

        $ttlF = max(5, (int) $prefs['ttl_forecast_min']);
        $mult = max(1, (int) $prefs['stale_multiplier']);
        $age = $fc ? (int) $fc->fetched_at->diffInMinutes(now()) : null;

        $base['current'] = $cur?->payload;
        $base['current_at'] = $cur?->fetched_at?->toDateTimeString();
        $base['current_due'] = ! $cur || $cur->expires_at->lte(now());
        $base['forecast'] = $fc?->payload;
        $base['fetched_at'] = $fc?->fetched_at?->toDateTimeString();
        $base['age_minutes'] = $age;
        $base['alerts'] = $conf['supports_alerts'] ? ($al?->payload ?? null) : null;
        $base['alerts_at'] = $al?->fetched_at?->toDateTimeString();
        $base['snapshot_ids'] = array_filter(['current' => $cur?->id, 'forecast' => $fc?->id, 'alerts' => $al?->id]);
        $base['uncertainty'] = $fc?->payload['uncertainty'] ?? null;
        $base['due'] = ! $fc || $fc->expires_at->lte(now()) || ($conf['supports_alerts'] && (! $al || $al->expires_at->lte(now())));

        if ($failed) {
            $base['last_error'] = ['message' => $attempt->error, 'at' => $attempt->fetched_at->toDateTimeString()];
            $base['retry_after'] = $attempt->expires_at?->toDateTimeString();
        }

        if (! $fc) {
            return ($failed
                ? ['status' => 'failed', 'message' => 'The weather provider did not answer and WIMS has no earlier data for this project. ' . $attempt->error]
                : ['status' => 'no_data', 'message' => 'No weather data has been fetched for this project yet.']) + $base;
        }
        if ($age > $ttlF * $mult) {
            $base['stale'] = true;
            $why = $failed ? ' The latest update failed: ' . $attempt->error : '';

            return ['status' => $failed ? 'failed' : 'stale', 'message' => 'This weather data is out of date (last updated ' . $fc->fetched_at->format('d M, H:i') . '). Do not rely on it.' . $why] + $base;
        }
        if ($failed && $age > $ttlF) {
            return ['status' => 'failed', 'message' => 'The latest update failed (' . $attempt->error . '). Showing the last successful update from ' . $fc->fetched_at->format('d M, H:i') . '.'] + $base;
        }

        return ['status' => 'ok'] + $base;
    }

    /** Is a provider call due? $context: schedule (forecast / alerts expired) or view (anything shown has expired). */
    public function needsFetch(array $state, string $context = 'view'): bool
    {
        if (in_array($state['status'], ['not_configured', 'no_location'], true)) {
            return false;
        }
        if ($state['retry_after'] && CarbonImmutable::parse($state['retry_after'])->gt(now())) {
            return false; // a call just failed: wait
        }

        return $state['due'] || ($context === 'view' && $state['current_due']);
    }

    // ------------------------------------------------------------------------------------------------------------------ fetching

    /** Seconds before the Refresh button may be used again for this project's location (0 = now). */
    public function cooldownRemaining(Warehouse $w): int
    {
        $loc = self::coordinates($w);
        if (! $loc) {
            return 0;
        }
        $accountId = (int) ($w->account_id ?: 1);
        $minutes = max(1, (int) $this->prefs($accountId)['manual_refresh_min']);
        $last = WeatherSnapshot::query()->where('account_id', $accountId)->where('location_key', $loc['key'])->where('trigger', 'manual')
            ->where('fetched_at', '>', now()->subMinutes($minutes))->max('fetched_at');

        return $last ? max(1, $minutes * 60 - (int) CarbonImmutable::parse($last)->diffInSeconds(now())) : 0;
    }

    /**
     * Fetch (when due, or when $force) and return the state. $trigger: schedule | view | manual. Never throws: a failure is saved and shown as state.
     * @return array state() plus 'fetched' => bool
     */
    public function ensure(Warehouse $w, string $trigger = 'view', ?User $by = null, bool $force = false): array
    {
        $state = $this->state($w);
        if (in_array($state['status'], ['not_configured', 'no_location'], true)) {
            return $state + ['fetched' => false];
        }
        if (! $force && ! $this->needsFetch($state, $trigger === 'schedule' ? 'schedule' : 'view')) {
            return $state + ['fetched' => false];
        }
        $loc = $state['location'];
        $accountId = (int) ($w->account_id ?: 1);
        $lock = Cache::lock('weather:fetch:' . $accountId . ':' . $loc['key'], 40);
        if (! $lock->get()) {
            return $state + ['fetched' => false]; // somebody else is fetching this place right now
        }
        try {
            // another request may have just refreshed it
            if (! $force) {
                $state = $this->state($w);
                if (! $this->needsFetch($state, $trigger === 'schedule' ? 'schedule' : 'view')) {
                    return $state + ['fetched' => false];
                }
            }
            $this->fetchLocation($accountId, $loc['lat'], $loc['lng'], $loc['key'], $w->id, $trigger, $by?->id);
        } finally {
            $lock->release();
        }

        return $this->state($w) + ['fetched' => true];
    }

    /** One provider call for one location, saved as snapshots (or as a failed row). @return array{ok:bool, message:string} */
    public function fetchLocation(int $accountId, float $lat, float $lng, string $key, ?int $warehouseId, string $trigger, ?int $userId): array
    {
        $prefs = $this->prefs($accountId);
        $provider = $this->provider($accountId);
        $conf = $this->configuration($accountId);
        $now = now();
        if (! $provider) {
            return ['ok' => false, 'message' => $conf['message']];
        }
        $common = ['account_id' => $accountId, 'location_key' => $key, 'latitude' => $lat, 'longitude' => $lng, 'warehouse_id' => $warehouseId, 'provider' => $provider->key(), 'trigger' => $trigger, 'requested_by' => $userId, 'fetched_at' => $now];

        try {
            $bundle = $provider->bundle($lat, $lng, max(24, min(168, (int) $prefs['forecast_hours'])));
        } catch (WeatherProviderException $e) {
            $msg = $this->safe($e->getMessage(), $accountId);
            WeatherSnapshot::query()->create($common + ['kind' => 'forecast', 'status' => 'failed', 'error' => $msg, 'expires_at' => $now->copy()->addMinutes(max(1, (int) $prefs['failure_backoff_min']))]);

            return ['ok' => false, 'message' => $msg];
        } catch (\Throwable $e) {
            $msg = $this->safe($provider->label() . ' failed unexpectedly (' . class_basename($e) . ').', $accountId);
            WeatherSnapshot::query()->create($common + ['kind' => 'forecast', 'status' => 'failed', 'error' => $msg, 'expires_at' => $now->copy()->addMinutes(max(1, (int) $prefs['failure_backoff_min']))]);

            return ['ok' => false, 'message' => $msg];
        }

        DB::transaction(function () use ($common, $bundle, $prefs, $now) {
            WeatherSnapshot::query()->create($common + ['kind' => 'current', 'payload' => $bundle['current'], 'status' => 'ok', 'expires_at' => $now->copy()->addMinutes((int) $prefs['ttl_current_min'])]);
            WeatherSnapshot::query()->create($common + ['kind' => 'forecast', 'payload' => $bundle['forecast'], 'status' => 'ok', 'expires_at' => $now->copy()->addMinutes((int) $prefs['ttl_forecast_min'])]);
            if ($bundle['alerts'] !== null) {
                WeatherSnapshot::query()->create($common + ['kind' => 'alerts', 'payload' => $bundle['alerts'], 'status' => 'ok', 'expires_at' => $now->copy()->addMinutes((int) $prefs['ttl_alerts_min'])]);
            }
        });

        return ['ok' => true, 'message' => ''];
    }

    /** One real call with fixed test coordinates (not saved as weather data). The outcome is saved in the settings so it can be shown. */
    public function testConnection(int $accountId): array
    {
        $provider = $this->provider($accountId);
        if (! $provider) {
            $r = ['ok' => false, 'message' => $this->configuration($accountId)['message']];
        } else {
            try {
                $b = $provider->bundle(-6.16, 39.19, 24);
                $r = ['ok' => true, 'message' => $provider->label() . ' answered: ' . count($b['forecast']['hourly']) . ' hourly forecast values' . ($provider->supportsAlerts() ? ', ' . count($b['alerts'] ?? []) . ' alert(s) for the test location.' : '. This provider does not supply severe-weather alerts.')];
            } catch (WeatherProviderException $e) {
                $r = ['ok' => false, 'message' => $this->safe($e->getMessage(), $accountId)];
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'message' => $this->safe($provider->label() . ' failed unexpectedly (' . class_basename($e) . ').', $accountId)];
            }
        }
        $r['at'] = now()->toDateTimeString();
        $r['provider'] = $this->prefs($accountId)['provider'];
        WeatherSettings::save(['last_test' => $r], $accountId);

        return $r;
    }

    /** Delete old snapshots (keeps those a warning points at). Returns the number deleted. */
    public function prune(int $accountId): int
    {
        $days = max(7, (int) $this->prefs($accountId)['snapshot_retention_days']);
        $keep = DB::table('weather_warnings')->whereNotNull('snapshot_id')->pluck('snapshot_id');

        return WeatherSnapshot::query()->where('account_id', $accountId)->where('fetched_at', '<', now()->subDays($days))->whereNotIn('id', $keep)->delete();
    }

    /** Whatever is stored or shown must not contain the key or the request address. */
    private function safe(string $text, int $accountId): string
    {
        if ($key = WeatherSettings::apiKey($accountId)) {
            $text = str_replace($key, '[key]', $text);
        }
        $text = preg_replace('/(appid|apikey|api_key|key)=[^&\s]+/i', '$1=[key]', $text);

        return mb_substr(preg_replace('#https?://\S+#i', '[address]', $text), 0, 300);
    }
}
