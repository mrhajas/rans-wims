<?php

/*
| WIMS Weather & Site Risk. Which provider is used, its address and its key are chosen on the Weather Settings page (stored in the settings
| table, the key encrypted). Nothing here is a secret: WEATHER_API_KEY / WEATHER_PROVIDER are optional deployment defaults used only when the
| Settings page has nothing saved. Never put a key in this file.
*/

return [
    'api_key' => env('WEATHER_API_KEY'),
    'provider' => env('WEATHER_PROVIDER'),

    // Where times are expressed (the database stores local time).
    'timezone' => env('APP_TIMEZONE', 'Africa/Dar_es_Salaam'),

    'providers' => [
        'open_meteo' => [
            'label' => 'Open-Meteo',
            'class' => \App\Services\Weather\Providers\OpenMeteoProvider::class,
            'requires_key' => false,
            'supports_alerts' => false,
            'default_base' => 'https://api.open-meteo.com',
            'commercial_base' => 'https://customer-api.open-meteo.com',
            'note' => 'The free Open-Meteo service is for non-commercial use. For company use, take a commercial plan, enter its key here and use the commercial address. Open-Meteo does not supply severe-weather alerts.',
        ],
        'openweathermap' => [
            'label' => 'OpenWeatherMap One Call 3.0',
            'class' => \App\Services\Weather\Providers\OpenWeatherMapProvider::class,
            'requires_key' => true,
            'supports_alerts' => true,
            'default_base' => 'https://api.openweathermap.org',
            'commercial_base' => null,
            'note' => 'Needs an OpenWeatherMap key with the One Call 3.0 plan. Supplies severe-weather alerts where national agencies publish them for the location.',
        ],
        'openweathermap_free' => [
            'label' => 'OpenWeatherMap (free plan)',
            'class' => \App\Services\Weather\Providers\OpenWeatherMapFreeProvider::class,
            'requires_key' => true,
            'supports_alerts' => false,
            'default_base' => 'https://api.openweathermap.org',
            'commercial_base' => null,
            'note' => 'Works with a plain free OpenWeatherMap key (no One Call subscription). Gives current conditions and a 5 day forecast in 3 hour steps. No severe-weather alerts.',
        ],
    ],
];
