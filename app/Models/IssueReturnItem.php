<?php

namespace App\Models;

class IssueReturnItem extends Model
{
    protected $fillable = [
        'issue_return_id',
        'issue_transaction_item_id',
        'item_id',
        'unit_id',
        'issued_qty',
        'previously_returned_qty',
        'balance_qty',
        'returning_qty',
        'condition',
        'remarks',
        'account_id',
    ];

    public $casts = [
        'issued_qty' => 'float',
        'previously_returned_qty' => 'float',
        'balance_qty' => 'float',
        'returning_qty' => 'float',
    ];

    public function issueReturn()
    {
        return $this->belongsTo(IssueReturn::class)->withTrashed();
    }

    public function issueTransactionItem()
    {
        return $this->belongsTo(IssueTransactionItem::class)->withTrashed();
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
