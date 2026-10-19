<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An adhan recording uploaded at Prayer Times > Settings. Its key in the settings is "custom-{id}". */
class PrayerAdhan extends Model
{
    protected $guarded = [];

    public function key(): string
    {
        return 'custom-'.$this->id;
    }
}
