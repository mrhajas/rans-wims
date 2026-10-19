<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable ledger row. A payment is never edited or deleted once made —
 * reversing one means creating a new negative-amount row that references
 * it, not touching the original (see SalaryRecord::totalPaid(), which sums
 * every row here rather than trusting any single "latest" one).
 */
class SalaryPayment extends Model
{
    protected $fillable = [
        'salary_record_id', 'amount', 'payment_date', 'payment_method',
        'payment_reference', 'paid_by_user_id', 'salary_voucher_id', 'notes', 'reverses_payment_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date:Y-m-d',
    ];

    public function record()
    {
        return $this->belongsTo(SalaryRecord::class, 'salary_record_id');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function voucher()
    {
        return $this->belongsTo(SalaryVoucher::class, 'salary_voucher_id');
    }

    public function reverses()
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_payment_id');
    }
}
