<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Money paid to a loading / unloading worker; a removed payment is kept (soft deleted) and no longer counts. */
class TransportLoadingPayment extends Eloquent
{
    use SoftDeletes;

    public const PAID = 'Paid';

    /** Created from a Payment Voucher that is not paid yet: the amount is reserved but the balance is not reduced. */
    public const PENDING_VOUCHER = 'Pending Voucher';

    protected $guarded = [];

    public function scopePaid($query)
    {
        return $query->where('status', self::PAID);
    }

    public function scopePendingVoucher($query)
    {
        return $query->where('status', self::PENDING_VOUCHER);
    }

    public function voucher()
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    protected $casts = ['payment_date' => 'date', 'amount' => 'float'];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
