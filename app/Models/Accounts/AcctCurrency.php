<?php

namespace App\Models\Accounts;

class AcctCurrency extends AcctModel
{
    protected $table = 'acct_currencies';
    protected $casts = ['is_active' => 'boolean', 'is_reporting' => 'boolean'];
}
