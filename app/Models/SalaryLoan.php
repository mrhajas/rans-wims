<?php

namespace App\Models;

use App\Traits\HasAttachments;

/**
 * Pass 1: interest-free, fixed-installment only (interest_rate column
 * exists for future extensibility but the engine below doesn't act on it
 * yet — see the plan's Decision 4). Outstanding principal is always
 * LIVE-COMPUTED from the immutable salary_loan_repayments ledger.
 */
class SalaryLoan extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const METHOD_FIXED_INSTALLMENT = 'Fixed Installment';

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
        'staff_id', 'reference', 'loan_date', 'principal_amount', 'interest_rate', 'repayment_method',
        'installments_count', 'installment_amount', 'first_deduction_period_id', 'status',
        'approved_by_user_id', 'approved_at', 'notes', 'created_by_user_id', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'loan_date' => 'date:Y-m-d',
        'principal_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
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
        return $this->hasMany(SalaryLoanRepayment::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function totalRepaidPrincipal(): float
    {
        return (float) $this->repayments()->sum('principal_portion');
    }

    public function outstandingPrincipal(): float
    {
        return round((float) $this->principal_amount - $this->totalRepaidPrincipal(), 2);
    }

    public function nextDeductionAmount(): float
    {
        return min((float) $this->installment_amount, $this->outstandingPrincipal());
    }
}
