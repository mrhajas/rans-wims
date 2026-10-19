<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrayerMessage extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'is_custom' => 'boolean'];
}
