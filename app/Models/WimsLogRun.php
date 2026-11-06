<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One run of WIMS Logs: when it ran, who started it, what it archived and what it saved. See App\Support\WimsLogs. */
class WimsLogRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sources' => 'array', 'options' => 'array', 'before' => 'array', 'after' => 'array', 'speed_before' => 'array', 'speed_after' => 'array',
        'rows_archived' => 'integer', 'original_bytes' => 'integer', 'stored_bytes' => 'integer', 'db_freed_bytes' => 'integer',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];

    public function archives()
    {
        return $this->hasMany(WimsLogArchive::class, 'run_id');
    }

    public function ref(): string
    {
        return 'LOG-' . str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}
