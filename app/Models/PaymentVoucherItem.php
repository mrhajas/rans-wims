<?php

namespace App\Models;

class PaymentVoucherItem extends Model
{
    public static $hasAccount = false;

    protected $fillable = ['line_number', 'particulars', 'amount', 'transport_record_id', 'transport_loading_payment_id'];

    public function paymentVoucher()
    {
        return $this->belongsTo(PaymentVoucher::class)->withoutGlobalScope('workflow_visibility');
    }

    /** Set when this line pays for a specific Transport Record — see TransportRecordController::createPaymentVoucher(). */
    public function transportRecord()
    {
        return $this->belongsTo(TransportRecord::class);
    }
}
