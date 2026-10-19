<?php

namespace App\Models;

class PemAuditLog extends Model
{
    public static $tracksDeletes = false;

    public $timestamps = true;

    protected $fillable = [
        'loggable_type',
        'loggable_id',
        'user_id',
        'role',
        'action',
        'previous_value',
        'new_value',
        'ip_address',
        'browser',
        'device',
        'remarks',
    ];

    public $casts = [
        'previous_value' => 'array',
        'new_value' => 'array',
    ];

    public function loggable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->where(function ($q) use ($s) {
                    $q->where('action', 'like', "%{$s}%")
                        ->orWhere('remarks', 'like', "%{$s}%")
                        ->orWhere('ip_address', 'like', "%{$s}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%"));
                });
            })
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['end_date'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
    }
}
