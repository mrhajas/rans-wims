<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One answer (or one failure) from the weather provider for one location (coordinates rounded to 2 decimals) and one kind: current | forecast | alerts. */
class WeatherSnapshot extends Eloquent
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'fetched_at' => 'datetime', 'expires_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];
}
