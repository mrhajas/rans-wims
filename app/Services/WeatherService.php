<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeatherService
{
    // RANS GODOWN GD1 (Zanzibar) — the account's primary operating location.
    // No per-account configured HQ exists yet, so this is a reasonable fixed
    // default rather than something derived per-request.
    protected const DEFAULT_LAT = -6.2596267;
    protected const DEFAULT_LON = 39.2592918;

    protected const CACHE_TTL_MINUTES = 30;

    protected const TIMEZONE = 'Africa/Dar_es_Salaam';

    public static function current(): ?array
    {
        // What is cached is the raw reading (temperature, WMO code, today's sunrise/sunset).
        // Day vs night is decided on every request from those times, so the icon flips to a
        // moon at sunset instead of waiting for the 30-minute cache to expire.
        $raw = Cache::remember('dashboard-weather-v2', now()->addMinutes(self::CACHE_TTL_MINUTES), function () {
            try {
                $response = Http::timeout(4)->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => self::DEFAULT_LAT,
                    'longitude' => self::DEFAULT_LON,
                    'current_weather' => 'true',
                    'daily' => 'sunrise,sunset',
                    'timezone' => self::TIMEZONE,
                ]);

                if (! $response->successful()) {
                    return null;
                }

                $current = $response->json('current_weather');

                if (! $current) {
                    return null;
                }

                return [
                    'temperature' => $current['temperature'],
                    'code' => (int) $current['weathercode'],
                    'sunrise' => $response->json('daily.sunrise.0'),
                    'sunset' => $response->json('daily.sunset.0'),
                ];
            } catch (\Throwable $e) {
                Log::warning('WeatherService: failed to fetch current weather', ['error' => $e->getMessage()]);

                return null;
            }
        });

        if (! $raw) {
            return null;
        }

        $isDay = self::isDaytime($raw['sunrise'] ?? null, $raw['sunset'] ?? null);
        [$description, $icon] = self::describeWeatherCode($raw['code'], $isDay);

        return [
            'temperature' => round($raw['temperature']),
            'description' => $description,
            'icon' => $icon,
            'is_day' => $isDay,
        ];
    }

    protected static function isDaytime(?string $sunrise, ?string $sunset): bool
    {
        $now = now(self::TIMEZONE);

        if ($sunrise && $sunset) {
            return $now->between(Carbon::parse($sunrise, self::TIMEZONE), Carbon::parse($sunset, self::TIMEZONE));
        }

        // No sun times available: fall back to a plain 6am-6pm day.
        return $now->hour >= 6 && $now->hour < 18;
    }

    /**
     * Maps Open-Meteo's WMO weather codes to a short description + emoji icon.
     * https://open-meteo.com/en/docs#weathervariables
     */
    protected static function describeWeatherCode(int $code, bool $isDay = true): array
    {
        return match (true) {
            $code === 0 => $isDay ? ['Clear Sky', '☀️'] : ['Clear Night', '🌙'],
            in_array($code, [1, 2], true) => $isDay ? ['Partly Cloudy', '🌤️'] : ['Partly Cloudy', '☁️'],
            $code === 3 => ['Overcast', '☁️'],
            in_array($code, [45, 48], true) => ['Foggy', '🌫️'],
            in_array($code, [51, 53, 55, 56, 57], true) => $isDay ? ['Drizzle', '🌦️'] : ['Drizzle', '🌧️'],
            in_array($code, [61, 63, 65, 66, 67], true) => ['Rain', '🌧️'],
            in_array($code, [71, 73, 75, 77], true) => ['Snow', '🌨️'],
            in_array($code, [80, 81, 82], true) => $isDay ? ['Rain Showers', '🌦️'] : ['Rain Showers', '🌧️'],
            in_array($code, [85, 86], true) => ['Snow Showers', '🌨️'],
            in_array($code, [95, 96, 99], true) => ['Thunderstorm', '⛈️'],
            default => ['Weather Unavailable', '🌡️'],
        };
    }
}
