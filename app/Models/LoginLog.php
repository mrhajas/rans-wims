<?php

namespace App\Models;

class LoginLog extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    protected $fillable = [
        'account_id',
        'user_id',
        'username',
        'role',
        'status',
        'ip_address',
        'device',
        'os',
        'browser',
        'location',
        'user_agent',
        'error_message',
        'attempted_at',
        'extra_attributes',
    ];

    public $casts = [
        'attempted_at' => 'datetime',
        'extra_attributes' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFilter($query, array $filters)
    {
        $sort = $filters['sort'] ?? 'attempted_at';
        $direction = strtolower($filters['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['attempted_at', 'username', 'role', 'status', 'ip_address', 'os', 'browser'];

        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'attempted_at';
        }

        return $query
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($nested) use ($search) {
                    $nested->where('username', 'like', "%{$search}%")
                        ->orWhere('role', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('device', 'like', "%{$search}%")
                        ->orWhere('os', 'like', "%{$search}%")
                        ->orWhere('browser', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%")
                        ->orWhere('error_message', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->whereDate('attempted_at', '>=', $d))
            ->when($filters['end_date'] ?? null, fn ($q, $d) => $q->whereDate('attempted_at', '<=', $d))
            ->orderBy($sort, $direction)
            ->orderByDesc('id');
    }
}
