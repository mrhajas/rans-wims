<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Http;

/**
 * Finds the coordinates of a place from its name or address, so a person does not have to look them up on a map. The result is only a
 * list of suggestions: nothing is saved until a person picks one and saves the location.
 *
 * Source: OpenWeatherMap's geocoding (works with a plain free key) when the weather provider is OpenWeatherMap and a key is set, otherwise
 * (or when that is not accepted yet) Open-Meteo's geocoding, which needs no key. The query is a project name or address only; the key is
 * never part of any message shown or logged.
 */
class Geocoder
{
    private const OPEN_METEO = 'https://geocoding-api.open-meteo.com/v1/search';

    /** @return array{candidates: list<array>, tried: list<string>, source: ?string, error: ?string} */
    public function lookup(string $address, string $name, int $accountId): array
    {
        $prefs = WeatherSettings::get($accountId);
        $key = WeatherSettings::apiKey($accountId);
        $useOwm = str_starts_with((string) $prefs['provider'], 'openweathermap') && $key;

        $tried = [];
        $error = null;
        foreach ($this->queries($address, $name) as $q) {
            $tried[] = $q;
            $found = [];
            $source = null;
            if ($useOwm) {
                try {
                    $found = $this->owm($q, $key, $prefs);
                    $source = 'OpenWeatherMap';
                } catch (\Throwable $e) {
                    $error = 'OpenWeatherMap did not answer the place search (' . $this->short($e) . '), so the free Open-Meteo place search was used instead.';
                }
            }
            if (! $found) {
                try {
                    $found = $this->openMeteo($q);
                    $source = 'Open-Meteo';
                } catch (\Throwable $e) {
                    $error = 'The place search could not be reached (' . $this->short($e) . ').';
                }
            }
            if ($found) {
                return ['candidates' => $found, 'tried' => $tried, 'source' => $source, 'error' => null];
            }
        }

        return ['candidates' => [], 'tried' => $tried, 'source' => null, 'error' => $error];
    }

    /** The full address first, then shorter and shorter endings of it (the town, the region), then the project name. */
    private function queries(string $address, string $name): array
    {
        $out = [];
        $parts = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $address))));
        if ($parts) {
            $out[] = implode(', ', $parts);
            for ($i = 1; $i < count($parts); $i++) {
                $out[] = implode(', ', array_slice($parts, $i));
            }
        }
        $name = trim($name);
        if ($name !== '') {
            $out[] = $name;
        }

        return array_values(array_unique(array_filter($out, fn ($q) => mb_strlen($q) >= 3)));
    }

    private function owm(string $q, string $key, array $prefs): array
    {
        $base = rtrim(trim((string) ($prefs['base_url'] ?? '')) ?: 'https://api.openweathermap.org', '/');
        $r = Http::acceptJson()->timeout(max(3, (int) $prefs['timeout_seconds']))->get($base . '/geo/1.0/direct', ['q' => $q, 'limit' => 5, 'appid' => $key]);
        if (! $r->successful()) {
            throw new \RuntimeException('HTTP ' . $r->status());
        }

        return collect($r->json() ?: [])->filter(fn ($p) => isset($p['lat'], $p['lon']))->map(fn ($p) => [
            'name' => (string) ($p['name'] ?? $q), 'region' => (string) ($p['state'] ?? ''), 'country' => (string) ($p['country'] ?? ''),
            'latitude' => round((float) $p['lat'], 6), 'longitude' => round((float) $p['lon'], 6),
        ])->values()->all();
    }

    private function openMeteo(string $q): array
    {
        $r = Http::acceptJson()->timeout(8)->get(self::OPEN_METEO, ['name' => $q, 'count' => 5, 'language' => 'en', 'format' => 'json']);
        if (! $r->successful()) {
            throw new \RuntimeException('HTTP ' . $r->status());
        }

        return collect($r->json('results') ?: [])->filter(fn ($p) => isset($p['latitude'], $p['longitude']))->map(fn ($p) => [
            'name' => (string) ($p['name'] ?? $q), 'region' => trim(implode(', ', array_filter([$p['admin2'] ?? null, $p['admin1'] ?? null]))), 'country' => (string) ($p['country'] ?? ($p['country_code'] ?? '')),
            'latitude' => round((float) $p['latitude'], 6), 'longitude' => round((float) $p['longitude'], 6),
        ])->values()->all();
    }

    private function short(\Throwable $e): string
    {
        return preg_replace('/(appid|apikey|key)=[^&\s]+/i', '$1=[key]', $e->getMessage());
    }
}
