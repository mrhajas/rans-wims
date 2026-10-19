<?php

namespace App\Services\Weather\Providers;

/**
 * Open-Meteo (https://open-meteo.com). Key optional: with a key the commercial address customer-api.open-meteo.com is used (the address can
 * be overridden in the settings). One /v1/forecast call returns current, hourly and daily data. Open-Meteo has no severe-weather alerts.
 */
class OpenMeteoProvider extends BaseProvider
{
    public function key(): string
    {
        return 'open_meteo';
    }

    public function label(): string
    {
        return 'Open-Meteo';
    }

    public function supportsAlerts(): bool
    {
        return false;
    }

    protected function fetch(float $lat, float $lng, int $hours): array
    {
        $cfg = config('weather.providers.open_meteo');
        $default = $this->apiKey ? $cfg['commercial_base'] : $cfg['default_base'];
        $days = max(2, min(10, (int) ceil($hours / 24) + 1));
        $query = [
            'latitude' => $lat, 'longitude' => $lng, 'timezone' => config('weather.timezone'), 'forecast_days' => $days,
            'wind_speed_unit' => 'kmh', 'precipitation_unit' => 'mm', 'temperature_unit' => 'celsius',
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,cloud_cover,wind_speed_10m,wind_direction_10m,wind_gusts_10m,is_day',
            'hourly' => 'temperature_2m,precipitation_probability,precipitation,weather_code,wind_speed_10m,wind_gusts_10m,wind_direction_10m',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_speed_10m_max,wind_gusts_10m_max,sunrise,sunset',
        ];
        if ($this->apiKey) {
            $query['apikey'] = $this->apiKey;
        }
        $json = $this->get($this->baseUrl($default) . '/v1/forecast', $query)->json();
        if (! is_array($json) || empty($json['hourly']['time'])) {
            throw $this->bad('no hourly forecast');
        }

        return ['current' => $this->current_($json), 'forecast' => $this->forecast_($json, $hours), 'alerts' => null];
    }

    private function current_(array $j): array
    {
        $c = $j['current'] ?? null;
        if (! is_array($c)) {
            throw $this->bad('no current conditions');
        }
        $icon = self::iconFromWmo($c['weather_code'] ?? null);

        return [
            'time' => $this->local($c['time'] ?? null), 'temperature_c' => $this->num($c['temperature_2m'] ?? null), 'feels_like_c' => $this->num($c['apparent_temperature'] ?? null),
            'humidity_pct' => $this->num($c['relative_humidity_2m'] ?? null, 0), 'precip_mm' => $this->num($c['precipitation'] ?? null),
            'wind_kmh' => $this->num($c['wind_speed_10m'] ?? null), 'gust_kmh' => $this->num($c['wind_gusts_10m'] ?? null), 'wind_dir_deg' => $this->num($c['wind_direction_10m'] ?? null, 0),
            'cloud_pct' => $this->num($c['cloud_cover'] ?? null, 0), 'condition' => self::iconText($icon), 'code' => (string) ($c['weather_code'] ?? ''), 'icon' => $icon,
            'is_day' => isset($c['is_day']) ? (bool) $c['is_day'] : null,
        ];
    }

    private function forecast_(array $j, int $hours): array
    {
        $h = $j['hourly'];
        $now = now(config('weather.timezone'))->startOfHour();
        $hourly = [];
        foreach ($h['time'] as $i => $t) {
            $time = $this->local($t);
            if (! $time || \Carbon\Carbon::parse($time, config('weather.timezone'))->lt($now)) {
                continue;
            }
            if (count($hourly) >= $hours) {
                break;
            }
            $icon = self::iconFromWmo($h['weather_code'][$i] ?? null);
            $hourly[] = [
                'time' => $time, 'temperature_c' => $this->num($h['temperature_2m'][$i] ?? null), 'precip_probability_pct' => isset($h['precipitation_probability'][$i]) ? (int) round($h['precipitation_probability'][$i]) : null,
                'precip_mm' => $this->num($h['precipitation'][$i] ?? null), 'wind_kmh' => $this->num($h['wind_speed_10m'][$i] ?? null), 'gust_kmh' => $this->num($h['wind_gusts_10m'][$i] ?? null),
                'wind_dir_deg' => $this->num($h['wind_direction_10m'][$i] ?? null, 0), 'condition' => self::iconText($icon), 'code' => (string) ($h['weather_code'][$i] ?? ''), 'icon' => $icon,
            ];
        }
        $d = $j['daily'] ?? ['time' => []];
        $daily = [];
        foreach (($d['time'] ?? []) as $i => $date) {
            $icon = self::iconFromWmo($d['weather_code'][$i] ?? null);
            $daily[] = [
                'date' => substr((string) $date, 0, 10), 'temp_min_c' => $this->num($d['temperature_2m_min'][$i] ?? null), 'temp_max_c' => $this->num($d['temperature_2m_max'][$i] ?? null),
                'precip_mm' => $this->num($d['precipitation_sum'][$i] ?? null), 'precip_probability_pct' => isset($d['precipitation_probability_max'][$i]) ? (int) round($d['precipitation_probability_max'][$i]) : null,
                'wind_kmh' => $this->num($d['wind_speed_10m_max'][$i] ?? null), 'gust_kmh' => $this->num($d['wind_gusts_10m_max'][$i] ?? null),
                'condition' => self::iconText($icon), 'icon' => $icon, 'sunrise' => $this->local($d['sunrise'][$i] ?? null), 'sunset' => $this->local($d['sunset'][$i] ?? null),
            ];
        }
        $hasProb = collect($hourly)->contains(fn ($x) => $x['precip_probability_pct'] !== null);

        return [
            'hourly' => $hourly, 'daily' => $daily,
            'uncertainty' => ($hasProb ? 'Rain probability is given by Open-Meteo for each hour. ' : 'Open-Meteo gave no rain probability for this location. ')
                . 'Open-Meteo gives no uncertainty range for wind or temperature. Forecasts are less certain further ahead.',
        ];
    }

    public static function iconFromWmo($code): string
    {
        if ($code === null || $code === '') {
            return 'unknown';
        }
        $c = (int) $code;

        return match (true) {
            $c === 0 => 'clear', in_array($c, [1, 2], true) => 'partly', $c === 3 => 'cloudy', in_array($c, [45, 48], true) => 'fog',
            in_array($c, [51, 53, 55, 56, 57], true) => 'drizzle', in_array($c, [61, 63, 65, 66, 67], true) => 'rain', in_array($c, [80, 81, 82], true) => 'showers',
            in_array($c, [71, 73, 75, 77, 85, 86], true) => 'snow', in_array($c, [95, 96, 99], true) => 'storm', default => 'unknown',
        };
    }
}
