<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImpersonationSession extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_TERMINATED = 'terminated';

    public const MODE_FULL = 'full';
    public const MODE_READ_ONLY = 'read_only';

    protected $fillable = [
        'code', 'account_id', 'original_user_id', 'target_user_id', 'reason', 'mode',
        'status', 'started_at', 'expires_at', 'ended_at', 'ended_by_user_id',
        'ip_address', 'user_agent',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function originalUser()
    {
        return $this->belongsTo(User::class, 'original_user_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['original_user_id'] ?? null, fn ($q, $v) => $q->where('original_user_id', $v));
        $query->when($filters['target_user_id'] ?? null, fn ($q, $v) => $q->where('target_user_id', $v));
        $query->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
        $query->when($filters['reason'] ?? null, fn ($q, $v) => $q->where('reason', $v));
        $query->when($filters['search'] ?? null, fn ($q, $v) => $q->where('reason', 'like', "%{$v}%"));
        $query->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('started_at', '>=', $v));
        $query->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('started_at', '<=', $v));
    }
}
