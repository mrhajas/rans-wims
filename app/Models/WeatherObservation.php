<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Weather as seen on site by a person. Never mixed with provider data: always labelled "Observed on site". */
class WeatherObservation extends Eloquent
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['observed_at' => 'datetime', 'diary_date' => 'date', 'rainfall_mm' => 'float', 'temperature_c' => 'float', 'wind_kmh' => 'float'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    public function workPackage()
    {
        return $this->belongsTo(WorkPackage::class)->withTrashed();
    }
}
