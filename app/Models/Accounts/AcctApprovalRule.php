<?php

namespace App\Models\Accounts;

class AcctApprovalRule extends AcctModel
{
    protected $table = 'acct_approval_rules';
    protected $casts = ['is_active' => 'boolean'];
}
