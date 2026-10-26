<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockArchiveWipe extends Model
{
    protected $guarded = [];

    protected $casts = ['entry_ids' => 'array', 'refs' => 'array', 'decided_at' => 'datetime'];

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id')->withoutGlobalScopes();
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id')->withoutGlobalScopes();
    }
}
