<?php

namespace App\Models;

class BoqMaterialCompliance extends Model
{
    protected $fillable = [
        'account_id',
        'project_warehouse_id',
        'warehouse_id',
        'item_id',
        'unit_price',
        'boq_quantity',
        'remarks',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function projectWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'project_warehouse_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function scopeOfAccount($query, $account = null)
    {
        return $query->where('account_id', $account ?? auth()->user()->account_id);
    }
}
