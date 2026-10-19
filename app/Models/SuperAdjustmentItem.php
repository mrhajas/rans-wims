<?php

namespace App\Models;

class SuperAdjustmentItem extends Model
{
    protected $fillable = [
        'super_adjustment_id', 'item_id', 'unit_id', 'system_quantity_at_submission', 'physical_quantity', 'auto_cleared', 'account_id',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'auto_cleared' => 'boolean',
    ];

    public function superAdjustment()
    {
        return $this->belongsTo(SuperAdjustment::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /** physical_quantity - live/current system stock, computed by the caller and never persisted. */
    public function varianceAgainst(float $liveSystemQuantity): float
    {
        return round((float) $this->physical_quantity - $liveSystemQuantity, 4);
    }
}
