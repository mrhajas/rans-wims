<?php

namespace App\Models;

/**
 * The link between an approved payment request and a Payment Voucher. It counts as PAID only when that voucher is Authorised and has a paid date:
 * see SubcontractLedger. A row that has no voucher yet, or whose voucher is Draft / Submitted / Approved / Rejected / Cancelled, is never money paid.
 */
class SubcontractPayment extends Model
{
    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public $casts = ['amount' => 'decimal:2', 'amount_tzs' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'rate_date' => 'date'];

    public function request()
    {
        return $this->belongsTo(SubcontractPaymentRequest::class, 'request_id');
    }

    public function agreement()
    {
        return $this->belongsTo(SubcontractAgreement::class, 'agreement_id');
    }

    public function voucher()
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    public function allocations()
    {
        return $this->hasMany(SubcontractPaymentAllocation::class, 'payment_id');
    }
}
