<?php

namespace App\Models;

use App\Traits\HasAttachments;

class SupplierInvoice extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_PENDING_MATCH = 'Pending Match';
    public const STATUS_MATCHED = 'Matched';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_PARTIALLY_PAID = 'Partially Paid';
    public const STATUS_PAID = 'Paid';
    public const STATUS_DISPUTED = 'Disputed';

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING_MATCH, self::STATUS_MATCHED, self::STATUS_APPROVED,
            self::STATUS_PARTIALLY_PAID, self::STATUS_PAID, self::STATUS_DISPUTED,
        ];
    }

    protected $fillable = [
        'reference', 'invoice_number', 'purchase_order_id', 'goods_receipt_id', 'supplier_id', 'warehouse_id',
        'invoice_date', 'due_date', 'status', 'match_status', 'subtotal', 'tax_amount', 'total',
        'amount_paid', 'remarks', 'created_by', 'extra_attributes',
        'approved_by_user_id', 'approved_on', 'disputed_by_user_id', 'disputed_on',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'invoice_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'approved_on' => 'datetime',
        'disputed_on' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(SupplierInvoiceItem::class)->orderBy('id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paymentRequests()
    {
        return $this->belongsToMany(PaymentRequest::class, 'payment_request_supplier_invoice');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function getBalanceAttribute()
    {
        return round($this->total - $this->amount_paid, 2);
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id));
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(fn ($q) => $q->where('reference', 'like', "%{$search}%")->orWhere('invoice_number', 'like', "%{$search}%"));
    }
}
