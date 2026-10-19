<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

class PreventRequestsDuringMaintenance extends Middleware
{
    protected $except = [
        // The public knowledge base stays readable while the app is in maintenance mode.
        'wimshow',
        'wimshow/*',
    ];
}
