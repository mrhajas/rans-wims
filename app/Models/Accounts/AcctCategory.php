<?php

namespace App\Models\Accounts;

class AcctCategory extends AcctModel
{
    protected $table = 'acct_categories';
    protected $casts = ['is_active' => 'boolean', 'is_system' => 'boolean'];
}
