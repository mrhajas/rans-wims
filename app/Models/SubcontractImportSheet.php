<?php

namespace App\Models;

class SubcontractImportSheet extends Model
{
    public static $hasAccount = false;

    public $casts = ['totals' => 'array', 'flags' => 'array', 'parsed' => 'array', 'as_at_date' => 'date'];

    public function batch()
    {
        return $this->belongsTo(SubcontractImportBatch::class, 'batch_id');
    }
}
