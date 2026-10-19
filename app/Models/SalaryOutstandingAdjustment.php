<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryOutstandingAdjustment extends Model
{
    public const TYPE_WAIVER = 'waiver';
    public const TYPE_CORRECTION = 'correction';
    public const TYPE_SETTLEMENT = 'settlement';
    public const TYPE_OPENING_BALANCE = 'opening_balance';

    protected $fillable = ['salary_record_id', 'type', 'amount', 'reason', 'created_by_user_id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function record()
    {
        return $this->belongsTo(SalaryRecord::class, 'salary_record_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
