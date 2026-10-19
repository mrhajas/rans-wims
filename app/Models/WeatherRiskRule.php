<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** The thresholds of one weather-sensitive activity. Starting values until a person marks them reviewed; never an engineering or safety limit by themselves. */
class WeatherRiskRule extends Eloquent
{
    protected $guarded = [];

    protected $casts = [
        'keywords' => 'array', 'is_active' => 'boolean', 'is_builtin' => 'boolean', 'reviewed_at' => 'datetime',
        'max_wind_kmh' => 'float', 'max_gust_kmh' => 'float', 'max_precip_mm_per_hour' => 'float', 'max_precip_mm_per_day' => 'float', 'min_temp_c' => 'float', 'max_temp_c' => 'float',
        'max_rain_probability_pct' => 'integer', 'lookahead_hours' => 'integer',
    ];

    /** The threshold columns, in the order they are shown. */
    public const THRESHOLDS = ['max_wind_kmh', 'max_gust_kmh', 'max_rain_probability_pct', 'max_precip_mm_per_hour', 'max_precip_mm_per_day', 'min_temp_c', 'max_temp_c'];

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id')->withTrashed();
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null && $this->reviewed_by_user_id !== null;
    }
}
