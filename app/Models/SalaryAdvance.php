<?php

namespace App\Models;

use App\Traits\HasAttachments;

/**
 * Remaining balance is always LIVE-COMPUTED from the immutable
 * salary_advance_repayments ledger, never stored — mirrors
 * WorkforceAgreement::outstandingBalance() exactly.
 */
class SalaryAdvance extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_PENDING = 'Pending';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED,
            self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
        ];
    }

    protected $fillable = [
        'staff_id', 'reference', 'advance_date', 'amount', 'reason', 'repayment_method',
        'installments_count', 'installment_amount', 'first_deduction_period_id', 'status',
        'approved_by_user_id', 'approved_at', 'notes', 'created_by_user_id', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'advance_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function firstDeductionPeriod()
    {
        return $this->belongsTo(SalaryPeriod::class, 'first_deduction_period_id');
    }

    public function repayments()
    {
        return $this->hasMany(SalaryAdvanceRepayment::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function totalRepaid(): float
    {
        return (float) $this->repayments()->sum('amount');
    }

    public function outstandingBalance(): float
    {
        return round((float) $this->amount - $this->totalRepaid(), 2);
    }

    /** Never deduct more than what's actually left — clamps the requested amount to the real remaining balance. */
    public function nextDeductionAmount(): float
    {
        return min((float) $this->installment_amount, $this->outstandingBalance());
    }
}
