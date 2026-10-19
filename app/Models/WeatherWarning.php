<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** A weather-related warning for a project: advisory only. Acknowledging it records who saw it and what they decided; it changes nothing else. */
class WeatherWarning extends Eloquent
{
    public const OPEN = 'open';
    public const ACKNOWLEDGED = 'acknowledged';
    public const EXPIRED = 'expired';
    public const DISMISSED = 'dismissed';

    protected $guarded = [];

    protected $casts = ['details' => 'array', 'window_start' => 'datetime', 'window_end' => 'datetime', 'acknowledged_at' => 'datetime', 'raised_at' => 'datetime', 'expired_at' => 'datetime', 'peak_value' => 'float', 'acknowledged_peak' => 'float'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function rule()
    {
        return $this->belongsTo(WeatherRiskRule::class, 'rule_id');
    }

    public function workPackage()
    {
        return $this->belongsTo(WorkPackage::class)->withTrashed();
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by')->withTrashed();
    }
}
