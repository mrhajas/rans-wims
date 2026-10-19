<?php

namespace App\Models;

class PaymentRequest extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const TYPE_INVOICE = 'invoice';
    public const TYPE_BALANCE = 'balance';

    public const STATUS_PENDING_APPROVAL = 'Pending Approval';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_PAID = 'Paid';

    public static function statuses(): array
    {
        return [self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_PAID];
    }

    protected $fillable = [
        'reference', 'type', 'supplier_id', 'warehouse_id', 'requested_by_user_id', 'date_requested',
        'amount', 'status', 'approval_remarks', 'extra_attributes',
        'submitted_by_user_id', 'submitted_at', 'approved_by_user_id', 'approved_at', 'rejected_by_user_id', 'rejected_at',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'date_requested' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function invoices()
    {
        return $this->belongsToMany(SupplierInvoice::class, 'payment_request_supplier_invoice');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeFilter($query, array $filters)
    {
        return $query->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
    }
}
