<?php

namespace App\Services\Weather;

class WeatherProviderException extends \RuntimeException
{
    /** @param string $kind unauthorised | rate_limited | unavailable | bad_response | not_configured */
    public function __construct(string $message, public readonly string $kind = 'unavailable')
    {
        parent::__construct($message);
    }
}
