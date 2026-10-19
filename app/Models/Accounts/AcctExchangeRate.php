<?php

namespace App\Models\Accounts;

class AcctExchangeRate extends AcctModel
{
    protected $table = 'acct_exchange_rates';
    protected $casts = ['rate_at' => 'datetime', 'effective_from' => 'date', 'effective_to' => 'date', 'approved_at' => 'datetime'];
}
