<?php

namespace App\Models;

class FundAllocationBalance extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = false;

    protected $fillable = ['fund_allocation_id', 'fund_account_id', 'label', 'currency', 'amount', 'exchange_rate', 'amount_tzs', 'sort_order'];

    public $casts = ['amount' => 'float', 'exchange_rate' => 'float', 'amount_tzs' => 'float'];

    public function allocation()
    {
        return $this->belongsTo(FundAllocation::class, 'fund_allocation_id')->withoutGlobalScope('workflow_visibility');
    }

    public function account()
    {
        return $this->belongsTo(FundAccount::class, 'fund_account_id')->withTrashed();
    }

    protected static function booted()
    {
        parent::booted();
        // TZS value is always derived here, never trusted from a client.
        static::saving(function (self $b) {
            $rate = strtoupper((string) $b->currency) === 'TZS' ? 1 : (float) $b->exchange_rate;
            $b->exchange_rate = $rate > 0 ? $rate : 1;
            $b->amount_tzs = round((float) $b->amount * $b->exchange_rate, 2);
        });
    }
}
