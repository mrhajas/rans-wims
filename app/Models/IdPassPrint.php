<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** One print or export of a card (or of a batch): what the application did, for whom, by whom, when. "Generated" means the file was made, not that a printer printed it. */
class IdPassPrint extends Eloquent
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['print' => 'Print', 'pdf' => 'PDF Download', 'batch_print' => 'Batch Print', 'batch_pdf' => 'Batch PDF Export'];

    public const LAYOUTS = ['card' => 'Card size (55 x 85 mm)', 'a4_side' => 'A4, front and back side by side', 'a4_duplex' => 'A4, double sided', 'report' => 'WIMS report'];

    protected $table = 'idpass_prints';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
        static::creating(function ($m) {
            if (! $m->account_id) {
                $m->account_id = getAccountId();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function card()
    {
        return $this->belongsTo(IdPassCard::class, 'card_id')->withTrashed();
    }

    public function batch()
    {
        return $this->belongsTo(IdPassBatch::class, 'batch_id');
    }
}
