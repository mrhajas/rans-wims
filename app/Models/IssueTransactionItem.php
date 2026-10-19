<?php

namespace App\Models;

class IssueTransactionItem extends Model
{
    protected $fillable = [
        'issue_transaction_id',
        'item_id',
        'unit_id',
        'issued_quantity',
        'returned_quantity',
        'damaged_quantity',
        'lost_quantity',
        'balance_quantity',
        'remarks',
        'sort_order',
        'account_id',
    ];

        public $casts = [
        'issued_quantity' => 'float',
        'returned_quantity' => 'float',
        'damaged_quantity' => 'float',
        'lost_quantity' => 'float',
        'balance_quantity' => 'float',
    ];

    public function issueTransaction()
    {
        return $this->belongsTo(IssueTransaction::class)->withTrashed();
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function returnItems()
    {
        return $this->hasMany(IssueReturnItem::class)->withTrashed();
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
