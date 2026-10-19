<?php

namespace App\Models;

class Notice extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $tracksDeletes = true;

    protected $fillable = [
        'title',
        'message',
        'send_to_all',
        'created_by',
        'extra_attributes',
    ];

    public $casts = [
        'send_to_all' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function targetRoles()
    {
        return $this->belongsToMany(Role::class, 'notice_role')->withTimestamps();
    }

    public function targetUsers()
    {
        return $this->belongsToMany(User::class, 'notice_user')->withTimestamps();
    }

    public function histories()
    {
        return $this->hasMany(NoticeHistory::class)->latest('sent_at');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($nested) use ($search) {
                    $nested->where('title', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%");
                });
            })
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['end_date'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
    }
};
