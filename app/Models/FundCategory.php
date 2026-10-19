<?php

namespace App\Models;

class FundCategory extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    protected $fillable = ['name', 'sort_order', 'is_active', 'is_system'];

    public $casts = ['is_active' => 'boolean', 'is_system' => 'boolean'];

    public function items()
    {
        return $this->hasMany(FundAllocationItem::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
