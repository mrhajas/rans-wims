<?php

namespace App\Models;

class SubcontractImportBatch extends Model
{
    public static $hasAccount = false;

    public $casts = ['summary' => 'array', 'imported_at' => 'datetime'];

    public function sheets()
    {
        return $this->hasMany(SubcontractImportSheet::class, 'batch_id')->orderBy('id');
    }

    public static function nextReference(): string
    {
        $prefix = 'SIB-' . now()->format('Y') . '-';
        $max = (int) \Illuminate\Support\Facades\DB::table('subcontract_import_batches')->where('reference', 'like', $prefix . '%')->selectRaw('MAX(CAST(SUBSTRING(reference, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) m')->value('m');

        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
