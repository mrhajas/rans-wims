<?php

namespace App\Models;

class CaseCategory extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = false;

    protected $fillable = ['name', 'is_active', 'sort_order'];

    public $casts = [
        'is_active' => 'boolean',
    ];

    public function cases()
    {
        return $this->hasMany(CaseTicket::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
