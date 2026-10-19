<?php

namespace App\Services\Weather\Providers;

use App\Services\Weather\WeatherProviderException;
use Carbon\Carbon;

/**
 * OpenWeatherMap on the plain free plan (https://openweathermap.org/current and /forecast5). A standard free key is enough: no One Call
 * subscription. Current conditions come from /data/2.5/weather and a 5 day forecast in 3 hour steps from /data/2.5/forecast. Each 3 hour
 * step is spread evenly over its three hours so hourly values and daily totals stay consistent. This plan has NO severe-weather alerts.
 */
class OpenWeatherMapFreeProvider extends OpenWeatherMapProvider
{
    public function key(): string
    {
        return 'openweathermap_free';
    }

    public function label(): string
    {
        return 'OpenWeatherMap (free plan)';
    }

    public function supportsAlerts(): bool
    {
        return false;
    }

    protected function fetch(float $lat, float $lng, int $hours): array
    {
        if (! $this->apiKey) {
            throw new WeatherProviderException('OpenWeatherMap needs a key. Add it on the Weather Settings page.', 'not_configured');
        }
        $base = $this->baseUrl(config('weather.providers.openweathermap_free.default_base'));
        $q = ['lat' => $lat, 'lon' => $lng, 'appid' => $this->apiKey, 'units' => 'metric'];
        $cur = $this->get($base . '/data/2.5/weather', $q)->json();
        if (! is_array($cur) || ! isset($cur['main'])) {
            throw $this->bad('no current conditions');
        }
        $fc = $this->get($base . '/data/2.5/forecast', $q + ['cnt' => 40])->json();
        if (! is_array($fc) || empty($fc['list'])) {
            throw $this->bad('no forecast');
        }

        return ['current' => $this->current3($cur), 'forecast' => $this->forecast3($fc['list'], $hours), 'alerts' => []];
    }

    private function at($unix): ?string
    {
        return is_numeric($unix) ? Carbon::createFromTimestamp((int) $unix, config('weather.timezone'))->format('Y-m-d H:i:s') : null;
    }

    private function kmh($ms): ?float
    {
        return is_numeric($ms) ? round((float) $ms * 3.6, 1) : null;
    }

    private function current3(array $c): array
    {
        $w = $c['weather'][0] ?? [];
        $icon = self::iconFromOwm($w['id'] ?? null);

        return [
            'time' => $this->at($c['dt'] ?? null), 'temperature_c' => $this->num($c['main']['temp'] ?? null), 'feels_like_c' => $this->num($c['main']['feels_like'] ?? null),
            'humidity_pct' => $this->num($c['main']['humidity'] ?? null, 0), 'precip_mm' => $this->num(($c['rain']['1h'] ?? 0) + ($c['snow']['1h'] ?? 0)),
            'wind_kmh' => $this->kmh($c['wind']['speed'] ?? null), 'gust_kmh' => $this->kmh($c['wind']['gust'] ?? null), 'wind_dir_deg' => $this->num($c['wind']['deg'] ?? null, 0),
            'cloud_pct' => $this->num($c['clouds']['all'] ?? null, 0), 'condition' => ucfirst((string) ($w['description'] ?? self::iconText($icon))), 'code' => (string) ($w['id'] ?? ''),
            'icon' => $icon, 'is_day' => isset($w['icon']) ? str_ends_with((string) $w['icon'], 'd') : null,
        ];
    }

    private function forecast3(array $list, int $hours): array
    {
        $now = now(config('weather.timezone'))->startOfHour();
        $hourly = [];
        foreach ($list as $step) {
            $start = Carbon::createFromTimestamp((int) ($step['dt'] ?? 0), config('weather.timezone'));
            $w = $step['weather'][0] ?? [];
            $icon = self::iconFromOwm($w['id'] ?? null);
            $mm = (($step['rain']['3h'] ?? 0) + ($step['snow']['3h'] ?? 0)) / 3;
            // the free forecast labels a step with the START of its 3 hours
            for ($h = 0; $h < 3; $h++) {
                $t = $start->copy()->addHours($h);
                if ($t->lt($now)) {
                    continue;
                }
                if (count($hourly) >= $hours) {
                    break 2;
                }
                $hourly[] = [
                    'time' => $t->format('Y-m-d H:i:s'), 'temperature_c' => $this->num($step['main']['temp'] ?? null), 'precip_probability_pct' => isset($step['pop']) ? (int) round($step['pop'] * 100) : null,
                    'precip_mm' => $this->num($mm), 'wind_kmh' => $this->kmh($step['wind']['speed'] ?? null), 'gust_kmh' => $this->kmh($step['wind']['gust'] ?? null),
                    'wind_dir_deg' => $this->num($step['wind']['deg'] ?? null, 0), 'condition' => ucfirst((string) ($w['description'] ?? self::iconText($icon))), 'code' => (string) ($w['id'] ?? ''), 'icon' => $icon,
                ];
            }
        }
        if (! $hourly) {
            throw $this->bad('no hourly forecast');
        }
        $daily = [];
        foreach (collect($hourly)->groupBy(fn ($h) => substr($h['time'], 0, 10)) as $date => $rows) {
            $temps = $rows->pluck('temperature_c')->filter(fn ($v) => $v !== null);
            $worst = $rows->sortByDesc(fn ($r) => $r['precip_mm'] ?? 0)->first();
            $daily[] = [
                'date' => $date, 'temp_min_c' => $temps->min(), 'temp_max_c' => $temps->max(), 'precip_mm' => $this->num($rows->sum('precip_mm')),
                'precip_probability_pct' => $rows->pluck('precip_probability_pct')->filter(fn ($v) => $v !== null)->max(), 'wind_kmh' => $rows->pluck('wind_kmh')->filter(fn ($v) => $v !== null)->max(),
                'gust_kmh' => $rows->pluck('gust_kmh')->filter(fn ($v) => $v !== null)->max(), 'condition' => $worst['condition'] ?? '', 'icon' => $worst['icon'] ?? 'unknown', 'sunrise' => null, 'sunset' => null,
            ];
        }

        return [
            'hourly' => $hourly, 'daily' => $daily,
            'uncertainty' => 'The free OpenWeatherMap plan gives the forecast in 3 hour steps for 5 days; WIMS spreads each step evenly over its three hours, so short bursts of rain or wind inside a step are not visible. The probability of precipitation is given for each step. It gives no uncertainty range for wind or temperature and no severe-weather alerts. Forecasts are less certain further ahead.',
        ];
    }
}
