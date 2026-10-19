<?php

namespace App\Models\Accounts;

class AcctAlert extends AcctModel
{
    protected $table = 'acct_alerts';
    protected $casts = ['read_at' => 'datetime', 'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime', 'last_seen_at' => 'datetime', 'escalated_at' => 'datetime'];
}
