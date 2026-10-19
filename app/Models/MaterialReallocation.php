<?php

namespace App\Models;

use App\Traits\HasAttachments;

class MaterialReallocation extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_SUBMITTED = 'Submitted';
    public const STATUS_PM_CLEARANCE = 'Project Manager Clearance';
    public const STATUS_WAREHOUSE_ALLOCATION = 'Warehouse Allocation';
    public const STATUS_DISPATCH_PREPARATION = 'Dispatch Preparation';
    public const STATUS_WAREHOUSE_RECEIVING = 'Warehouse Receiving';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_REQUEST_CHANGES = 'Request Changes';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_CANCELLED = 'Cancelled';

    public const REASON_OTHER = 'Other (Requires Explanation)';

    public const REASONS = [
        'Project Completed',
        'Excess Material',
        'Unused Material',
        'Material No Longer Required',
        'Project Scope Reduced',
        'Design Change',
        'Wrong Material Issued',
        'Temporary Return',
        'Project Suspended / On Hold',
        'Client Instruction',
        'Damaged Packaging (Usable Material)',
        self::REASON_OTHER,
    ];

    public static function reasons(): array
    {
        return self::REASONS;
    }

    protected $fillable = [
        'reference',
        'source_warehouse_id',
        'user_id',
        'status',
        'date_requested',
        'reason',
        'reason_details',
        'remarks',
        'approval_remarks',
        'submitted_on',
        'cleared_by_user_id',
        'cleared_on',
        'warehouse_allocated_by_user_id',
        'warehouse_allocated_on',
        'driver_contact_id',
        'vehicle',
        'dispatched_by_user_id',
        'dispatch_date_time',
        'received_by_user_id',
        'received_date_time',
        'final_approved_by_user_id',
        'final_approval_date_time',
        'completed_on',
        'extra_attributes',
    ];

    public $casts = [
        'date_requested' => 'date:Y-m-d',
        'submitted_on' => 'datetime',
        'cleared_on' => 'datetime',
        'warehouse_allocated_on' => 'datetime',
        'dispatch_date_time' => 'datetime',
        'received_date_time' => 'datetime',
        'final_approval_date_time' => 'datetime',
        'completed_on' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SUBMITTED,
            self::STATUS_PM_CLEARANCE,
            self::STATUS_WAREHOUSE_ALLOCATION,
            self::STATUS_DISPATCH_PREPARATION,
            self::STATUS_WAREHOUSE_RECEIVING,
            self::STATUS_COMPLETED,
            self::STATUS_REQUEST_CHANGES,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ];
    }

    public static function forwardStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SUBMITTED,
            self::STATUS_PM_CLEARANCE,
            self::STATUS_WAREHOUSE_ALLOCATION,
            self::STATUS_DISPATCH_PREPARATION,
            self::STATUS_WAREHOUSE_RECEIVING,
            self::STATUS_COMPLETED,
        ];
    }

    public static function terminalStatuses(): array
    {
        return [
            self::STATUS_COMPLETED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ];
    }

    public static function lockedStatuses(): array
    {
        return self::terminalStatuses();
    }

    public function canBeEditedBySiteEngineer(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REQUEST_CHANGES], true);
    }

    public function items()
    {
        return $this->hasMany(MaterialReallocationItem::class)->orderBy('id');
    }

    public function logs()
    {
        return $this->hasMany(MaterialReallocationLog::class)->orderBy('id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sourceWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function driverContact()
    {
        return $this->belongsTo(Contact::class, 'driver_contact_id');
    }

    public function clearedBy()
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }

    public function warehouseAllocatedBy()
    {
        return $this->belongsTo(User::class, 'warehouse_allocated_by_user_id');
    }

    public function dispatchedBy()
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function finalApprovedBy()
    {
        return $this->belongsTo(User::class, 'final_approved_by_user_id');
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->search($s))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['source_warehouse_id'] ?? null, fn ($q, $id) => $q->where('source_warehouse_id', $id))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->whereDate('date_requested', '>=', $d))
            ->when($filters['end_date'] ?? null, fn ($q, $d) => $q->whereDate('date_requested', '<=', $d));
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('reference', 'like', "%{$search}%")
                ->orWhere('reason', 'like', "%{$search}%")
                ->orWhereHas('sourceWarehouse', fn ($w) => $w->where('name', 'like', "%{$search}%"))
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
        });
    }
}
