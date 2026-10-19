<?php

namespace App\Services\Weather\Providers;

/**
 * OpenWeatherMap One Call API 3.0 (https://openweathermap.org/api/one-call-3). Needs a key. One call returns current, hourly (48 h), daily
 * and national severe-weather alerts. Wind arrives in m/s and is converted to km/h.
 */
class OpenWeatherMapProvider extends BaseProvider
{
    /** The address override must be the API host. The website address (openweathermap.org) is a common mistake and cannot answer API calls, so it is ignored. */
    protected function baseUrl(string $default): string
    {
        $custom = strtolower((string) parse_url(trim((string) ($this->prefs['base_url'] ?? '')), PHP_URL_HOST));
        if (in_array($custom, ['openweathermap.org', 'www.openweathermap.org'], true)) {
            return rtrim($default, '/');
        }

        return parent::baseUrl($default);
    }

    public function key(): string
    {
        return 'openweathermap';
    }

    public function label(): string
    {
        return 'OpenWeatherMap';
    }

    public function supportsAlerts(): bool
    {
        return true;
    }

    protected function fetch(float $lat, float $lng, int $hours): array
    {
        if (! $this->apiKey) {
            throw new \App\Services\Weather\WeatherProviderException('OpenWeatherMap needs a key. Add it on the Weather Settings page.', 'not_configured');
        }
        $json = $this->get($this->baseUrl(config('weather.providers.openweathermap.default_base')) . '/data/3.0/onecall', [
            'lat' => $lat, 'lon' => $lng, 'appid' => $this->apiKey, 'units' => 'metric', 'exclude' => 'minutely',
        ])->json();
        if (! is_array($json) || ! isset($json['current'])) {
            throw $this->bad('no current conditions');
        }

        return ['current' => $this->current_($json['current']), 'forecast' => $this->forecast_($json, $hours), 'alerts' => $this->alerts_($json['alerts'] ?? [])];
    }

    private function ts($unix): ?string
    {
        return is_numeric($unix) ? \Carbon\Carbon::createFromTimestamp((int) $unix, config('weather.timezone'))->format('Y-m-d H:i:s') : null;
    }

    private function kmh($ms): ?float
    {
        return is_numeric($ms) ? round((float) $ms * 3.6, 1) : null;
    }

    private function current_(array $c): array
    {
        $w = $c['weather'][0] ?? [];
        $icon = self::iconFromOwm($w['id'] ?? null);
        $isDay = isset($w['icon']) ? str_ends_with((string) $w['icon'], 'd') : null;

        return [
            'time' => $this->ts($c['dt'] ?? null), 'temperature_c' => $this->num($c['temp'] ?? null), 'feels_like_c' => $this->num($c['feels_like'] ?? null),
            'humidity_pct' => $this->num($c['humidity'] ?? null, 0), 'precip_mm' => $this->num(($c['rain']['1h'] ?? 0) + ($c['snow']['1h'] ?? 0)),
            'wind_kmh' => $this->kmh($c['wind_speed'] ?? null), 'gust_kmh' => $this->kmh($c['wind_gust'] ?? null), 'wind_dir_deg' => $this->num($c['wind_deg'] ?? null, 0),
            'cloud_pct' => $this->num($c['clouds'] ?? null, 0), 'condition' => ucfirst((string) ($w['description'] ?? self::iconText($icon))), 'code' => (string) ($w['id'] ?? ''), 'icon' => $icon, 'is_day' => $isDay,
        ];
    }

    private function forecast_(array $j, int $hours): array
    {
        $now = now(config('weather.timezone'))->startOfHour();
        $hourly = [];
        foreach (($j['hourly'] ?? []) as $h) {
            $time = $this->ts($h['dt'] ?? null);
            if (! $time || \Carbon\Carbon::parse($time, config('weather.timezone'))->lt($now)) {
                continue;
            }
            if (count($hourly) >= $hours) {
                break;
            }
            $w = $h['weather'][0] ?? [];
            $icon = self::iconFromOwm($w['id'] ?? null);
            $hourly[] = [
                'time' => $time, 'temperature_c' => $this->num($h['temp'] ?? null), 'precip_probability_pct' => isset($h['pop']) ? (int) round($h['pop'] * 100) : null,
                'precip_mm' => $this->num(($h['rain']['1h'] ?? 0) + ($h['snow']['1h'] ?? 0)), 'wind_kmh' => $this->kmh($h['wind_speed'] ?? null), 'gust_kmh' => $this->kmh($h['wind_gust'] ?? null),
                'wind_dir_deg' => $this->num($h['wind_deg'] ?? null, 0), 'condition' => ucfirst((string) ($w['description'] ?? self::iconText($icon))), 'code' => (string) ($w['id'] ?? ''), 'icon' => $icon,
            ];
        }
        $daily = [];
        foreach (($j['daily'] ?? []) as $d) {
            $w = $d['weather'][0] ?? [];
            $icon = self::iconFromOwm($w['id'] ?? null);
            $daily[] = [
                'date' => substr((string) $this->ts($d['dt'] ?? null), 0, 10), 'temp_min_c' => $this->num($d['temp']['min'] ?? null), 'temp_max_c' => $this->num($d['temp']['max'] ?? null),
                'precip_mm' => $this->num(($d['rain'] ?? 0) + ($d['snow'] ?? 0)), 'precip_probability_pct' => isset($d['pop']) ? (int) round($d['pop'] * 100) : null,
                'wind_kmh' => $this->kmh($d['wind_speed'] ?? null), 'gust_kmh' => $this->kmh($d['wind_gust'] ?? null), 'condition' => ucfirst((string) ($w['description'] ?? self::iconText($icon))), 'icon' => $icon,
                'sunrise' => $this->ts($d['sunrise'] ?? null), 'sunset' => $this->ts($d['sunset'] ?? null),
            ];
        }
        if (! $hourly) {
            throw $this->bad('no hourly forecast');
        }

        return [
            'hourly' => $hourly, 'daily' => $daily,
            'uncertainty' => 'OpenWeatherMap gives the probability of precipitation for each hour. It gives no uncertainty range for wind or temperature, and hourly data covers about 48 hours; later days are daily values only. Forecasts are less certain further ahead.',
        ];
    }

    private function alerts_(array $list): array
    {
        $out = [];
        foreach ($list as $a) {
            $start = $this->ts($a['start'] ?? null);
            $end = $this->ts($a['end'] ?? null);
            $event = trim((string) ($a['event'] ?? 'Weather alert'));
            $out[] = [
                'id' => substr(sha1(($a['sender_name'] ?? '') . '|' . $event . '|' . ($a['start'] ?? '')), 0, 16), 'event' => $event, 'sender' => (string) ($a['sender_name'] ?? ''),
                'start' => $start, 'end' => $end, 'description' => trim((string) ($a['description'] ?? '')), 'severity' => null, 'tags' => array_values((array) ($a['tags'] ?? [])),
            ];
        }

        return $out;
    }

    public static function iconFromOwm($id): string
    {
        if (! is_numeric($id)) {
            return 'unknown';
        }
        $i = (int) $id;

        return match (true) {
            $i >= 200 && $i < 300 => 'storm', $i >= 300 && $i < 400 => 'drizzle', $i >= 500 && $i < 600 => 'rain', $i >= 600 && $i < 700 => 'snow',
            $i >= 700 && $i < 800 => 'fog', $i === 800 => 'clear', in_array($i, [801, 802], true) => 'partly', in_array($i, [803, 804], true) => 'cloudy', default => 'unknown',
        };
    }
}
