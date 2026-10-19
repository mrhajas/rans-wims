<?php

namespace App\Models;

class SubcontractReconciliation extends Model
{
    public static $hasAccount = false;

    public $casts = ['approved_at' => 'datetime'];
}
