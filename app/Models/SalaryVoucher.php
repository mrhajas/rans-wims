<?php

namespace App\Models;

class SalaryVoucher extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_BULK = 'bulk';

    protected $fillable = [
        'voucher_number', 'type', 'salary_period_id', 'staff_id', 'parent_id',
        'gross_amount', 'total_deductions', 'net_salary', 'opening_outstanding_balance',
        'current_payment', 'remaining_balance',
        'prepared_by_user_id', 'approved_by_user_id', 'paid_by_user_id',
        'payment_date', 'payment_method', 'payment_reference',
        'printed_count', 'first_printed_at', 'notes', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'gross_amount' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'opening_outstanding_balance' => 'decimal:2',
        'current_payment' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'payment_date' => 'date:Y-m-d',
        'first_printed_at' => 'datetime',
    ];

    public function period()
    {
        return $this->belongsTo(SalaryPeriod::class, 'salary_period_id')->withoutGlobalScope('workflow_visibility');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
