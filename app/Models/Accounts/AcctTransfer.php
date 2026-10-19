<?php

namespace App\Models\Accounts;

class AcctTransfer extends AcctModel
{
    protected $table = 'acct_transfers';

    protected $casts = ['transfer_date' => 'date', 'submitted_at' => 'datetime', 'posted_at' => 'datetime'];

    public function from()
    {
        return $this->belongsTo(AcctAccount::class, 'from_account_id');
    }

    public function to()
    {
        return $this->belongsTo(AcctAccount::class, 'to_account_id');
    }

    public function legs()
    {
        return $this->hasMany(AcctTransaction::class, 'transfer_id');
    }
}
