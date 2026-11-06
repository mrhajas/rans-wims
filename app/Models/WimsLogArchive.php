<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The archived records of one log in one run (a data file and its index under storage/app/wims-logs). See App\Support\WimsLogs\ArchiveStore. */
class WimsLogArchive extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rows_n' => 'integer', 'original_bytes' => 'integer', 'compressed_bytes' => 'integer', 'stored_bytes' => 'integer', 'skipped_n' => 'integer',
        'restored_n' => 'integer', 'verified' => 'boolean', 'closed' => 'boolean', 'date_from' => 'datetime', 'date_to' => 'datetime', 'restored_at' => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(WimsLogRun::class, 'run_id');
    }

    public function ref(): string
    {
        return 'ARC-' . str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}
