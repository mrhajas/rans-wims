<?php

namespace App\Models\Accounts;

class AcctApproval extends AcctModel
{
    protected $table = 'acct_approvals';
    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];
}
