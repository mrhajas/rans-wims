<?php

namespace App\Models;

class DownloadPrintLog extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    protected $fillable = [
        'account_id',
        'user_id',
        'user_name',
        'role',
        'action_type',
        'module',
        'route_name',
        'method',
        'url',
        'file_name',
        'ip_address',
        'device',
        'os',
        'browser',
        'location',
        'user_agent',
        'status_code',
        'performed_at',
        'extra_attributes',
    ];

    public $casts = [
        'performed_at' => 'datetime',
        'extra_attributes' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFilter($query, array $filters)
    {
        $sort = $filters['sort'] ?? 'performed_at';
        $direction = strtolower($filters['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['performed_at', 'user_name', 'action_type', 'module', 'ip_address', 'browser', 'status_code'];

        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'performed_at';
        }

        return $query
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($nested) use ($search) {
                    $nested->where('user_name', 'like', "%{$search}%")
                        ->orWhere('role', 'like', "%{$search}%")
                        ->orWhere('action_type', 'like', "%{$search}%")
                        ->orWhere('module', 'like', "%{$search}%")
                        ->orWhere('route_name', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%")
                        ->orWhere('file_name', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('device', 'like', "%{$search}%")
                        ->orWhere('os', 'like', "%{$search}%")
                        ->orWhere('browser', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%");
                });
            })
            ->when($filters['action_type'] ?? null, fn ($q, $v) => $q->where('action_type', $v))
            ->when($filters['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->whereDate('performed_at', '>=', $d))
            ->when($filters['end_date'] ?? null, fn ($q, $d) => $q->whereDate('performed_at', '<=', $d))
            ->orderBy($sort, $direction)
            ->orderByDesc('id');
    }
}
