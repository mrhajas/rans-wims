<?php

namespace App\Models;

class WorkPackageMaterialForecast extends Model
{
    public static $tracksDeletes = true;

    protected $fillable = [
        'work_package_id', 'item_id', 'required_quantity', 'required_date', 'notes', 'created_by',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'required_date' => 'date',
        'required_quantity' => 'float',
    ];

    public function workPackage()
    {
        return $this->belongsTo(WorkPackage::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function availableStock(): float
    {
        return (float) (Stock::query()
            ->main()
            ->ofWarehouse($this->workPackage->warehouse_id)
            ->ofItem($this->item_id)
            ->value('quantity') ?? 0);
    }

    public function shortage(): float
    {
        return max(0, $this->required_quantity - $this->availableStock());
    }
}
