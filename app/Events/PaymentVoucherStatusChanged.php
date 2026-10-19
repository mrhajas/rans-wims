<?php

namespace App\Events;

use App\Models\PaymentVoucher;

class PaymentVoucherStatusChanged
{
    public function __construct(public PaymentVoucher $paymentVoucher)
    {
    }
}
