<?php

namespace App\Models;

class ItemPriceHistory extends Model
{
    protected $setUser = true;

    public $casts = [
        'price' => 'float',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    protected $fillable = [
        'item_id', 'user_id', 'price', 'effective_from', 'effective_to', 'remarks',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
