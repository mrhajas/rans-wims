<?php

namespace App\Models;

class CasePriority extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = false;

    protected $fillable = ['name', 'level', 'color', 'is_active'];

    public $casts = [
        'is_active' => 'boolean',
        'level' => 'integer',
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
        return $query->orderByDesc('level')->orderBy('name');
    }
}
