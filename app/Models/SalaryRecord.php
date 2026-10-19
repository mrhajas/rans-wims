<?php

namespace App\Models;

class SalaryRecord extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_PENDING_APPROVAL = 'Pending Approval';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_PARTIALLY_PAID = 'Partially Paid';
    public const STATUS_PAID = 'Paid';
    public const STATUS_REJECTED = 'Rejected';

    protected $fillable = [
        'salary_period_id', 'staff_id', 'basic_salary', 'total_earnings', 'total_deductions',
        'advance_deduction', 'loan_deduction', 'net_salary', 'opening_outstanding_balance',
        'current_payable', 'status', 'created_by_user_id', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'basic_salary' => 'decimal:2',
        'total_earnings' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'advance_deduction' => 'decimal:2',
        'loan_deduction' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'opening_outstanding_balance' => 'decimal:2',
        'current_payable' => 'decimal:2',
    ];

    public function period()
    {
        return $this->belongsTo(SalaryPeriod::class, 'salary_period_id')->withoutGlobalScope('workflow_visibility');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function items()
    {
        return $this->hasMany(SalaryRecordItem::class);
    }

    public function payments()
    {
        return $this->hasMany(SalaryPayment::class);
    }

    public function adjustments()
    {
        return $this->hasMany(SalaryOutstandingAdjustment::class);
    }

    /** Never trust a stored "remaining" column — always sum the immutable payment ledger. */
    public function totalPaid(): float
    {
        // lists load the sum with the records (withSum) or the payments themselves: one query for the whole list instead of one per record
        if (array_key_exists('payments_sum_amount', $this->attributes)) {
            return (float) $this->attributes['payments_sum_amount'];
        }
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments->sum('amount');
        }

        return (float) $this->payments()->sum('amount');
    }

    public function remainingBalance(): float
    {
        return round((float) $this->current_payable - $this->totalPaid(), 2);
    }

    public function paymentStatus(): string
    {
        $remaining = $this->remainingBalance();
        $paid = $this->totalPaid();

        if ($remaining <= 0.0) {
            return self::STATUS_PAID;
        }

        return $paid > 0 ? self::STATUS_PARTIALLY_PAID : $this->status;
    }
}
