<?php

namespace App\Models;

use App\Traits\HasAttachments;

class WorkPackageDailyUpdate extends Model
{
    use HasAttachments;

    public const STATUS_PENDING = 'Pending Approval';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';

    protected $fillable = [
        'work_package_id',
        'activities_completed',
        'progress_percent',
        'remarks',
        'weather',
        'manpower',
        'safety_observations',
        'inspection_results',
        'status',
        'submitted_by',
        'approved_by',
        'approved_at',
        'approval_remarks',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'manpower' => 'array',
        'progress_percent' => 'float',
        'approved_at' => 'datetime',
    ];

    public function workPackage()
    {
        return $this->belongsTo(WorkPackage::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['work_package_id'] ?? null, fn ($q, $id) => $q->where('work_package_id', $id));
    }
}
