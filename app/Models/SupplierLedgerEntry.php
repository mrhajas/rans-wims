<?php

namespace App\Models;

use App\Traits\HasAttachments;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierLedgerEntry extends Model
{
    use HasAttachments, SoftDeletes;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    // Debit types — increase what the company owes the supplier's ledger
    // shows as outstanding (a charge against the company).
    public const TYPE_OPENING_BALANCE = 'opening_balance';
    public const TYPE_MATERIAL_OBLIGATION = 'material_obligation';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_SUPPLIER_INVOICE = 'supplier_invoice';
    public const TYPE_OTHER_CHARGE = 'other_charge';
    public const TYPE_DEBIT_ADJUSTMENT = 'debit_adjustment';

    // Credit types — reduce the outstanding balance.
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_CREDIT_NOTE = 'credit_note';
    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_RETENTION = 'retention';
    public const TYPE_WITHHOLDING_TAX = 'withholding_tax';
    public const TYPE_CREDIT_ADJUSTMENT = 'credit_adjustment';

    public const DEBIT_TYPES = [
        self::TYPE_OPENING_BALANCE, self::TYPE_MATERIAL_OBLIGATION, self::TYPE_PURCHASE,
        self::TYPE_SUPPLIER_INVOICE, self::TYPE_OTHER_CHARGE, self::TYPE_DEBIT_ADJUSTMENT,
    ];

    public const CREDIT_TYPES = [
        self::TYPE_PAYMENT, self::TYPE_CREDIT_NOTE, self::TYPE_DISCOUNT,
        self::TYPE_RETENTION, self::TYPE_WITHHOLDING_TAX, self::TYPE_CREDIT_ADJUSTMENT,
    ];

    public const MANUAL_TYPES = [
        self::TYPE_OTHER_CHARGE, self::TYPE_DEBIT_ADJUSTMENT, self::TYPE_CREDIT_NOTE,
        self::TYPE_DISCOUNT, self::TYPE_RETENTION, self::TYPE_WITHHOLDING_TAX, self::TYPE_CREDIT_ADJUSTMENT,
    ];

    public const STATUS_PENDING = 'Pending';
    public const STATUS_VERIFIED = 'Verified';
    public const STATUS_POSTED = 'Posted';

    public static function directionFor(string $type): string
    {
        return in_array($type, self::CREDIT_TYPES, true) ? 'credit' : 'debit';
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_OPENING_BALANCE => 'Opening Balance',
            self::TYPE_MATERIAL_OBLIGATION => 'Material Obligation',
            self::TYPE_PURCHASE => 'Purchase',
            self::TYPE_SUPPLIER_INVOICE => 'Supplier Invoice',
            self::TYPE_OTHER_CHARGE => 'Other Charge',
            self::TYPE_DEBIT_ADJUSTMENT => 'Debit Adjustment',
            self::TYPE_PAYMENT => 'Payment',
            self::TYPE_CREDIT_NOTE => 'Credit Note',
            self::TYPE_DISCOUNT => 'Discount',
            self::TYPE_RETENTION => 'Retention',
            self::TYPE_WITHHOLDING_TAX => 'Withholding Tax',
            self::TYPE_CREDIT_ADJUSTMENT => 'Credit Adjustment',
        ];
    }

    protected $fillable = [
        'supplier_id', 'warehouse_id', 'entry_date', 'type', 'direction', 'amount',
        'reference', 'description', 'source_type', 'source_id', 'status',
        'created_by', 'verified_by', 'verified_on', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'entry_date' => 'date:Y-m-d',
        'verified_on' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('entry_date', '<=', $d));
    }
}
