<?php

namespace App\Models;

use App\Traits\HasAttachments;

class PurchaseRequest extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_PENDING_APPROVAL = 'Pending Approval';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_CONVERTED = 'Converted to PO';

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CONVERTED,
        ];
    }

    protected $fillable = [
        'reference', 'warehouse_id', 'user_id', 'status', 'date_requested',
        'reason', 'approval_remarks', 'extra_attributes',
        'submitted_by_user_id', 'submitted_at', 'approved_by_user_id', 'approved_at', 'rejected_by_user_id', 'rejected_at',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'date_requested' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseRequestItem::class)->orderBy('id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->search($s))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('reference', 'like', "%{$search}%");
    }
}
