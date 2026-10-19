<?php

namespace App\Models;

use App\Traits\HasAttachments;

class PurchaseOrder extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    // Shown when a PO has no warehouse/project — i.e. it's for the company itself.
    public const COMPANY_NAME = 'RANS Company Limited';

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_SUBMITTED = 'Submitted';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_SENT = 'Sent to Supplier';
    public const STATUS_ACKNOWLEDGED = 'Acknowledged';
    public const STATUS_PARTIALLY_DELIVERED = 'Partially Delivered';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_CANCELLED = 'Cancelled';

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_SENT,
            self::STATUS_ACKNOWLEDGED, self::STATUS_PARTIALLY_DELIVERED, self::STATUS_COMPLETED,
            self::STATUS_REJECTED, self::STATUS_CANCELLED,
        ];
    }

    protected $fillable = [
        'reference', 'supplier_id', 'warehouse_id', 'purchase_request_id', 'quotation_id', 'user_id',
        'status', 'date_issued', 'expected_delivery_date', 'currency', 'tax_included', 'tax_percentage',
        'subtotal', 'tax_amount', 'total', 'terms', 'remarks', 'approval_remarks',
        'submitted_on', 'approved_by_user_id', 'approved_on', 'sent_on', 'acknowledged_on', 'cancelled_on',
        'submitted_by_user_id', 'sent_by_user_id', 'acknowledged_by_user_id', 'cancelled_by_user_id', 'rejected_by_user_id', 'rejected_on',
        'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'date_issued' => 'date:Y-m-d',
        'expected_delivery_date' => 'date:Y-m-d',
        'tax_included' => 'boolean',
        'submitted_on' => 'datetime',
        'approved_on' => 'datetime',
        'sent_on' => 'datetime',
        'acknowledged_on' => 'datetime',
        'cancelled_on' => 'datetime',
        'rejected_on' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class)->withoutGlobalScope('workflow_visibility');
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function supplierInvoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['status'] ?? null, function ($q, $s) {
                // "In Progress" is a synthetic grouping the "In Progress"
                // dashboard card filters to — not a real status value —
                // matching the same three statuses the client used to
                // filter for locally before this became a server-side query.
                if ($s === 'In Progress') {
                    return $q->whereIn('status', [self::STATUS_SENT, self::STATUS_ACKNOWLEDGED, self::STATUS_PARTIALLY_DELIVERED]);
                }

                return $q->where('status', $s);
            })
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->search($s));
    }

    public function scopeSearch($query, $search)
    {
        // Matches reference or supplier name — the search box used to run
        // client-side against both fields before this moved server-side.
        return $query->where(function ($q) use ($search) {
            $q->where('reference', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
        });
    }
}
