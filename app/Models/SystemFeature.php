<?php

namespace App\Models;

class SystemFeature extends Model
{
    public static $hasAccount = false;

    public $casts = [
        'is_enabled' => 'boolean',
        'route_prefixes' => 'array',
    ];
}
