<?php

namespace App\Services\Weather\Providers;

use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Shared request handling: configurable address, timeouts, one retry, errors turned into messages that never contain the key. */
abstract class BaseProvider implements WeatherProvider
{
    private array $memo = [];

    public function __construct(protected array $prefs, protected ?string $apiKey = null)
    {
    }

    abstract protected function fetch(float $lat, float $lng, int $hours): array;

    protected function baseUrl(string $default): string
    {
        $custom = trim((string) ($this->prefs['base_url'] ?? ''));

        return rtrim($custom !== '' ? $custom : $default, '/');
    }

    public function current(float $lat, float $lng): array
    {
        return $this->bundle($lat, $lng)['current'];
    }

    public function forecast(float $lat, float $lng, int $hours = 72): array
    {
        return $this->bundle($lat, $lng, $hours)['forecast'];
    }

    public function alerts(float $lat, float $lng): array
    {
        return $this->bundle($lat, $lng)['alerts'] ?? [];
    }

    public function bundle(float $lat, float $lng, int $hours = 72): array
    {
        $k = round($lat, 4) . ',' . round($lng, 4) . ',' . $hours;

        return $this->memo[$k] ??= $this->fetch($lat, $lng, $hours);
    }

    /** One GET with timeouts and a single retry on a connection problem or a 5xx. */
    protected function get(string $url, array $query): Response
    {
        $timeout = max(2, (int) ($this->prefs['timeout_seconds'] ?? 8));
        $retries = max(0, min(3, (int) ($this->prefs['retries'] ?? 1)));
        try {
            $response = Http::acceptJson()->connectTimeout(min(5, $timeout))->timeout($timeout)
                ->retry($retries + 1, 300, fn ($e) => $e instanceof ConnectionException || (method_exists($e, 'getCode') && $e->getCode() >= 500), throw: false)
                ->get($url, $query);
        } catch (\Throwable $e) {
            throw new WeatherProviderException($this->scrub($this->label() . ' could not be reached (' . class_basename($e) . ').'), 'unavailable');
        }
        if ($response->successful()) {
            return $response;
        }
        $status = $response->status();
        if (in_array($status, [401, 403], true)) {
            throw new WeatherProviderException($this->label() . ' did not accept the key (HTTP ' . $status . '). Check the key and the plan on the Weather Settings page.', 'unauthorised');
        }
        if ($status === 429) {
            throw new WeatherProviderException($this->label() . ' says too many requests were made (HTTP 429). WIMS will try again later.', 'rate_limited');
        }
        throw new WeatherProviderException($this->label() . ' answered with an error (HTTP ' . $status . ').', 'unavailable');
    }

    /** Remove anything that looks like the key from text that may be stored or shown. */
    protected function scrub(string $text): string
    {
        if ($this->apiKey) {
            $text = str_replace($this->apiKey, '[key]', $text);
        }

        return preg_replace('/(appid|apikey|api_key|key)=[^&\s]+/i', '$1=[key]', $text);
    }

    protected function local(?string $iso): ?string
    {
        if (! $iso) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($iso, config('weather.timezone'))->setTimezone(config('weather.timezone'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function num($v, int $dec = 1): ?float
    {
        return is_numeric($v) ? round((float) $v, $dec) : null;
    }

    protected function bad(string $why): WeatherProviderException
    {
        return new WeatherProviderException($this->label() . ' sent data WIMS could not read (' . $why . ').', 'bad_response');
    }

    /** Words shown for an icon key. */
    public static function iconText(string $icon): string
    {
        return [
            'clear' => 'Clear', 'partly' => 'Partly cloudy', 'cloudy' => 'Cloudy', 'fog' => 'Fog or mist', 'drizzle' => 'Drizzle', 'rain' => 'Rain',
            'showers' => 'Rain showers', 'storm' => 'Thunderstorm', 'snow' => 'Snow', 'unknown' => 'Not described',
        ][$icon] ?? 'Not described';
    }
}
