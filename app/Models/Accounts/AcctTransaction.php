<?php

namespace App\Models\Accounts;

class AcctTransaction extends AcctModel
{
    protected $table = 'acct_transactions';

    public const STATUS = ['draft' => 'Draft', 'submitted' => 'Awaiting approval', 'approved' => 'Approved', 'posted' => 'Posted', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'reversed' => 'Reversed'];

    /** Entries that count in a balance: a reversed original stays in the ledger, and its linked reversal cancels it. */
    public const LEDGER = ['posted', 'reversed'];

    /**
     * Restrict a query to real income and expense: not the legs of a transfer between company accounts, and not the reversal of such a leg
     * (a reversed transfer is still internal money movement). Works on an Eloquent or a plain query builder.
     */
    public static function operating($q)
    {
        return $q->where('kind', '<>', 'transfer')->where(fn ($w) => $w->where('kind', '<>', 'reversal')->orWhereNull('transfer_id')
            ->orWhereNotIn('reverses_id', fn ($s) => $s->select('o.id')->from('acct_transactions as o')->where('o.kind', 'transfer')));
    }

    protected $casts = ['txn_date' => 'date', 'value_date' => 'date', 'method_details' => 'array', 'submitted_at' => 'datetime', 'posted_at' => 'datetime', 'reconciled_at' => 'datetime'];

    public function account()
    {
        return $this->belongsTo(AcctAccount::class, 'account_id');
    }

    public function category()
    {
        return $this->belongsTo(AcctCategory::class, 'category_id');
    }

    public function transfer()
    {
        return $this->belongsTo(AcctTransfer::class, 'transfer_id');
    }

    public function attachments()
    {
        return $this->hasMany(AcctAttachment::class, 'subject_id')->where('subject_type', 'transaction');
    }
}
