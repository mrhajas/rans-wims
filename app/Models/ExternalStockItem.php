<?php

namespace App\Models;

class ExternalStockItem extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    protected $table = 'external_stock_items';

    protected $fillable = ['movement_id', 'item_id', 'unit_id', 'quantity', 'note'];

    public $casts = ['quantity' => 'float'];

    public function movement()
    {
        return $this->belongsTo(ExternalStockMovement::class, 'movement_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }
}
