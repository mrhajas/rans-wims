<?php

namespace App\Models;

/** A payment made before WIMS (from the Excel workbook). Never a Payment Voucher. "opening_paid" rows are counted; "dated_evidence" rows only document a date and are never counted. */
class SubcontractHistoricalPayment extends Model
{
    public static $hasAccount = false;

    public $casts = ['counted' => 'boolean', 'amount' => 'decimal:2', 'amount_tzs' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'payment_date' => 'date', 'rate_date' => 'date'];
}
