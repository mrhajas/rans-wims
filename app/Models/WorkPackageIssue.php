<?php

namespace App\Models;

use App\Traits\HasAttachments;

class WorkPackageIssue extends Model
{
    use HasAttachments;

    public static $tracksDeletes = true;

    public const PRIORITY_LOW = 'Low';
    public const PRIORITY_MEDIUM = 'Medium';
    public const PRIORITY_HIGH = 'High';
    public const PRIORITY_CRITICAL = 'Critical';

    public const STATUS_OPEN = 'Open';
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_RESOLVED = 'Resolved';
    public const STATUS_CLOSED = 'Closed';

    protected $fillable = [
        'reference', 'work_package_id', 'title', 'description', 'priority', 'status',
        'assigned_user_id', 'deadline', 'resolution', 'resolved_at', 'resolved_by', 'created_by',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'deadline' => 'date',
        'resolved_at' => 'datetime',
    ];

    public static function priorities(): array
    {
        return [self::PRIORITY_LOW, self::PRIORITY_MEDIUM, self::PRIORITY_HIGH, self::PRIORITY_CRITICAL];
    }

    public static function statuses(): array
    {
        return [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED];
    }

    public function workPackage()
    {
        return $this->belongsTo(WorkPackage::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLogs()
    {
        return $this->morphMany(PemAuditLog::class, 'loggable')->orderByDesc('created_at');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(function ($w) use ($s) {
                $w->where('reference', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%");
            }))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($filters['assigned_user_id'] ?? null, fn ($q, $id) => $q->where('assigned_user_id', $id));
    }
}
