<?php

namespace App\Services\Weather;

/**
 * A weather data source. Every method returns the same normalised, metric shapes whatever the provider sends, so the rest of WIMS never
 * sees a provider's own format:
 *
 *  current:  [time, temperature_c, feels_like_c, humidity_pct, precip_mm, wind_kmh, gust_kmh, wind_dir_deg, cloud_pct, condition, code, icon, is_day]
 *  forecast: ['hourly' => list of [time, temperature_c, precip_probability_pct|null, precip_mm, wind_kmh, gust_kmh|null, wind_dir_deg, condition, code, icon],
 *             'daily' => list of [date, temp_min_c, temp_max_c, precip_mm, precip_probability_pct|null, wind_kmh, gust_kmh|null, condition, icon, sunrise, sunset],
 *             'uncertainty' => string (what the provider says about uncertainty, or that it says nothing)]
 *  alerts:   list of [id, event, sender, start, end, description, severity (minor|moderate|severe|extreme|null), tags]
 *
 * Times are local ('Y-m-d H:i:s'). A provider throws WeatherProviderException on any failure, with a message that is safe to show
 * (it never contains the key or the request address).
 */
interface WeatherProvider
{
    public function key(): string;

    public function label(): string;

    public function supportsAlerts(): bool;

    public function current(float $lat, float $lng): array;

    public function forecast(float $lat, float $lng, int $hours = 72): array;

    /** Severe-weather alerts for the location; an empty list when there are none OR the provider does not supply them (see supportsAlerts()). */
    public function alerts(float $lat, float $lng): array;

    /** One request that serves all three (both supported providers answer current, forecast and alerts in one call). ['current' => .., 'forecast' => .., 'alerts' => list|null (null = not supported)] */
    public function bundle(float $lat, float $lng, int $hours = 72): array;
}
