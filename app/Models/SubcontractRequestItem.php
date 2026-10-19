<?php

namespace App\Models;

class SubcontractRequestItem extends Model
{
    public static $hasAccount = false;

    public $casts = ['amount' => 'decimal:2'];

    public function workItem()
    {
        return $this->belongsTo(SubcontractWorkItem::class, 'work_item_id');
    }
}
