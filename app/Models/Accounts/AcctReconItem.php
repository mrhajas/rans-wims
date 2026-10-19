<?php

namespace App\Models\Accounts;

class AcctReconItem extends AcctModel
{
    protected $table = 'acct_recon_items';
    protected $casts = ['entry_date' => 'date'];
}
