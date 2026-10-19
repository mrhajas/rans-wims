<?php

namespace App\Models;

class MaterialReallocationItem extends Model
{
    public static $hasAccount = false;

    public static $tracksDeletes = true;

    protected $fillable = [
        'material_reallocation_id',
        'item_id',
        'unit_id',
        'requested_quantity',
        'destination_warehouse_id',
        'allocated_quantity',
        'received_quantity',
        'remarks',
        'parent_item_id',
    ];

    public $casts = [
        'requested_quantity' => 'float',
        'allocated_quantity' => 'float',
        'received_quantity' => 'float',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function materialReallocation()
    {
        return $this->belongsTo(MaterialReallocation::class);
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function parent()
    {
        return $this->belongsTo(MaterialReallocationItem::class, 'parent_item_id');
    }

    public function children()
    {
        return $this->hasMany(MaterialReallocationItem::class, 'parent_item_id');
    }
}
