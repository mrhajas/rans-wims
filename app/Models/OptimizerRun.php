<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One optimisation of the data (Settings > Optimize): who ran it, what was done and what was gained. See App\Support\Optimizer. */
class OptimizerRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tasks' => 'array', 'options' => 'array', 'before' => 'array', 'after' => 'array', 'results' => 'array',
        'speed_before' => 'array', 'speed_after' => 'array', 'saved_bytes' => 'integer',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];
}
