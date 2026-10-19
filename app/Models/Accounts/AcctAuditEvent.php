<?php

namespace App\Models\Accounts;

class AcctAuditEvent extends AcctModel
{
    protected $table = 'acct_audit';
    public $timestamps = false;

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
}
