<?php

namespace App\Models;

class FundAccount extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const TYPES = ['bank', 'cash', 'mobile', 'other'];

    protected $fillable = ['name', 'type', 'currency', 'account_number', 'description', 'is_active', 'sort_order', 'created_by_user_id'];

    public $casts = ['is_active' => 'boolean'];

    protected static function booted()
    {
        parent::booted();
        // Accounts Tracking follows this list: a fund account added, renamed, switched off or removed is reflected there straight away.
        $follow = fn (self $a) => \App\Services\Accounts\AcctFundSync::one($a);
        static::saved($follow);
        static::deleted($follow);
        static::restored($follow);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
