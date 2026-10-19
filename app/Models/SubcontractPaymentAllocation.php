<?php

namespace App\Models;

class SubcontractPaymentAllocation extends Model
{
    public static $hasAccount = false;

    public $casts = ['amount' => 'decimal:2'];
}
