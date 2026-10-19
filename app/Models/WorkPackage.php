<?php

namespace App\Models;

use App\Traits\HasAttachments;

class WorkPackage extends Model
{
    use HasAttachments;

    public static $tracksDeletes = true;

    public const STATUS_NOT_STARTED = 'Not Started';
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_ON_HOLD = 'On Hold';
    public const STATUS_COMPLETED = 'Completed';

    public const PRIORITY_LOW = 'Low';
    public const PRIORITY_NORMAL = 'Normal';
    public const PRIORITY_HIGH = 'High';
    public const PRIORITY_URGENT = 'Urgent';

    protected $fillable = [
        'reference',
        'title',
        'category',
        'warehouse_id',
        'building',
        'floor',
        'zone',
        'area',
        'engineer_id',
        'foreman_id',
        'subcontractor_id',
        'priority',
        'planned_start',
        'planned_finish',
        'planned_duration',
        'dependencies',
        'expected_progress_weight',
        'actual_progress_percent',
        'status',
        'created_by',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'dependencies' => 'array',
        'planned_start' => 'date',
        'planned_finish' => 'date',
        'expected_progress_weight' => 'float',
        'actual_progress_percent' => 'float',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_NOT_STARTED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_ON_HOLD,
            self::STATUS_COMPLETED,
        ];
    }

    public static function priorities(): array
    {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_NORMAL,
            self::PRIORITY_HIGH,
            self::PRIORITY_URGENT,
        ];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function engineer()
    {
        return $this->belongsTo(User::class, 'engineer_id');
    }

    public function foreman()
    {
        return $this->belongsTo(Contact::class, 'foreman_id');
    }

    public function subcontractor()
    {
        return $this->belongsTo(Contact::class, 'subcontractor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLogs()
    {
        return $this->morphMany(PemAuditLog::class, 'loggable')->orderByDesc('created_at');
    }

    public function dailyUpdates()
    {
        return $this->hasMany(WorkPackageDailyUpdate::class)->orderByDesc('id');
    }

    public function materialForecasts()
    {
        return $this->hasMany(WorkPackageMaterialForecast::class)->orderByDesc('id');
    }

    public function labourForecasts()
    {
        return $this->hasMany(WorkPackageLabourForecast::class)->orderByDesc('id');
    }

    public function equipmentForecasts()
    {
        return $this->hasMany(WorkPackageEquipmentForecast::class)->orderByDesc('id');
    }

    public function issues()
    {
        return $this->hasMany(WorkPackageIssue::class)->orderByDesc('id');
    }

    public function isAssignedTo(User $user): bool
    {
        return (int) $this->engineer_id === (int) $user->id;
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->search($s))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p));
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('reference', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%")
                ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$search}%"));
        });
    }
}
