<?php

namespace App\Models;

class AssetDepreciationEntry extends Model
{
    public static $hasAccount = false;

    public $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'posted_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
