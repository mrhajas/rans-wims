<?php

namespace App\Models;

use App\Traits\HasAttachments;

class LabourList extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_SUBMITTED = 'Submitted';
    public const STATUS_RETURNED = 'Returned';
    public const STATUS_CLEARED = 'Cleared';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_PAYMENT_VOUCHER_CREATED = 'Payment Voucher Created';

    protected $fillable = [
        'reference', 'warehouse_id', 'period_start', 'period_end', 'status', 'description', 'total_amount',
        'created_by_user_id', 'submitted_by_user_id', 'submitted_at',
        'cleared_by_user_id', 'cleared_at', 'approved_by_user_id', 'approved_at',
        'review_remarks', 'payment_voucher_batch_id', 'revises_labour_list_id', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'cleared_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SUBMITTED,
            self::STATUS_RETURNED,
            self::STATUS_CLEARED,
            self::STATUS_APPROVED,
            self::STATUS_PAYMENT_VOUCHER_CREATED,
        ];
    }

    /** Editable only while Draft or Returned — everything past that is policy-locked. */
    public static function editableStatuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_RETURNED];
    }

    public function items()
    {
        return $this->hasMany(LabourListItem::class)->orderBy('id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function clearedBy()
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function batch()
    {
        return $this->belongsTo(PaymentVoucherBatch::class, 'payment_voucher_batch_id');
    }

    /** Every voucher generated from this labour list — for the read-time payment rollup (see paymentRollupStatus()). */
    public function paymentVouchers()
    {
        return $this->hasMany(PaymentVoucher::class, 'labour_list_id');
    }

    public function revises()
    {
        return $this->belongsTo(self::class, 'revises_labour_list_id');
    }

    public function revisions()
    {
        return $this->hasMany(self::class, 'revises_labour_list_id');
    }

    /**
     * DERIVED, never stored — "Paid" only once every generated voucher has
     * actually been paid, mirroring PaymentVoucherBatch's own synthetic
     * status computation. Only meaningful once status is already
     * STATUS_PAYMENT_VOUCHER_CREATED; returns null before that.
     */
    public function paymentRollupStatus(): ?string
    {
        if ($this->status !== self::STATUS_PAYMENT_VOUCHER_CREATED) {
            return null;
        }

        $vouchers = $this->relationLoaded('paymentVouchers') ? $this->paymentVouchers : $this->paymentVouchers()->get();

        if ($vouchers->isEmpty()) {
            return 'Unpaid';
        }

        if ($vouchers->every(fn ($v) => ! is_null($v->paid_at))) {
            // "Paid" needs a paid voucher for EVERY labourer: a voucher that was deleted leaves its labourer unpaid, which must show as an issue, not as Paid
            return $vouchers->count() >= $this->items()->count() ? 'Paid' : 'Issue';
        }

        if ($vouchers->contains(fn ($v) => in_array($v->status, [PaymentVoucher::STATUS_REJECTED, PaymentVoucher::STATUS_CANCELLED], true))) {
            return 'Issue';
        }

        return 'Processing';
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('reference', 'like', "%{$search}%")
                        ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('period_start', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('period_end', '<=', $d))
            // Priority lives on the child LabourListItem, not this list
            // itself — matches a list that has AT LEAST ONE line item at
            // that priority, same "any matching line" semantics as the
            // search filter's warehouse-name match above.
            ->when($filters['priority'] ?? null, fn ($q, $p) => $q->whereHas('items', fn ($i) => $i->where('priority', $p)));
    }
}
