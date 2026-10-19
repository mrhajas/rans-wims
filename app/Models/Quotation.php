<?php

namespace App\Models;

class Quotation extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_SENT = 'Sent';
    public const STATUS_RECEIVED = 'Received';
    public const STATUS_SELECTED = 'Selected';
    public const STATUS_REJECTED = 'Rejected';

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_RECEIVED, self::STATUS_SELECTED, self::STATUS_REJECTED];
    }

    protected $fillable = [
        'reference', 'purchase_request_id', 'supplier_id', 'warehouse_id', 'date', 'valid_until',
        'status', 'total', 'remarks', 'created_by', 'extra_attributes',
        'sent_by_user_id', 'sent_at', 'received_by_user_id', 'received_at', 'selected_by_user_id', 'selected_at', 'rejected_by_user_id', 'rejected_at',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'date' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'selected_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(QuotationItem::class)->orderBy('id');
    }

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class)->withoutGlobalScope('workflow_visibility');
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

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id));
    }
}
