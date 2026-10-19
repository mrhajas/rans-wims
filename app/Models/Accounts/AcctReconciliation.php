<?php

namespace App\Models\Accounts;

class AcctReconciliation extends AcctModel
{
    protected $table = 'acct_reconciliations';
    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'denominations' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(AcctReconItem::class, 'reconciliation_id');
    }

    public function account()
    {
        return $this->belongsTo(AcctAccount::class, 'account_id');
    }
}
