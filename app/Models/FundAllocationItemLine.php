<?php

namespace App\Models;

class FundAllocationItemLine extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = false;

    protected $fillable = ['fund_allocation_item_id', 'fund_payment_method_id', 'amount', 'exchange_rate', 'usd_amount', 'reference', 'is_paid', 'paid_at', 'paid_by_user_id'];

    public $casts = ['amount' => 'float', 'exchange_rate' => 'float', 'usd_amount' => 'float', 'is_paid' => 'boolean', 'paid_at' => 'datetime'];

    public function item()
    {
        return $this->belongsTo(FundAllocationItem::class, 'fund_allocation_item_id');
    }

    public function method()
    {
        return $this->belongsTo(FundPaymentMethod::class, 'fund_payment_method_id')->withTrashed();
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
