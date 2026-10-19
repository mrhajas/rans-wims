<?php

namespace App\Models;

class SalaryPeriod extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_PREPARING = 'Preparing';
    public const STATUS_PENDING_APPROVAL = 'Pending Approval';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_PARTIALLY_PAID = 'Partially Paid';
    public const STATUS_PAID = 'Paid';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_CLOSED = 'Closed';

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT, self::STATUS_PREPARING, self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED, self::STATUS_PARTIALLY_PAID, self::STATUS_PAID,
            self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_CLOSED,
        ];
    }

    protected $fillable = [
        'label', 'period_start', 'period_end', 'batch_reference', 'status',
        'prepared_by_user_id', 'prepared_at', 'approved_by_user_id', 'approved_at',
        'rejection_reason', 'notes', 'created_by_user_id', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'prepared_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function records()
    {
        return $this->hasMany(SalaryRecord::class);
    }

    public function vouchers()
    {
        return $this->hasMany(SalaryVoucher::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function totalPayable(): float
    {
        return round((float) $this->records()->sum('current_payable'), 2);
    }

    public function totalPaid(): float
    {
        return (float) SalaryPayment::whereIn('salary_record_id', fn ($q) => $q->select('id')->from('salary_records')->where('salary_period_id', $this->id)->whereNull('deleted_at'))->sum('amount');
    }

    public function totalOutstanding(): float
    {
        return round($this->totalPayable() - $this->totalPaid(), 2);
    }

    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('label', 'like', "%{$search}%")
                        ->orWhere('batch_reference', 'like', "%{$search}%");
                });
            });
    }
}
