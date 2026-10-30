<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** Cards printed or exported together. Few cards are made at once; a large batch is made by a queued job and followed by its progress. */
class IdPassBatch extends Eloquent
{
    protected $table = 'idpass_batches';

    protected $guarded = [];

    protected $casts = ['filters' => 'array', 'card_ids' => 'array', 'completed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function nextReference(): string
    {
        $max = (int) \Illuminate\Support\Facades\DB::table('idpass_batches')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'IDB-%')->value('m');

        return 'IDB-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
