<?php

namespace App\Models;

class FundPaymentMethod extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    protected $fillable = ['name', 'short_label', 'currency', 'sort_order', 'is_active'];

    public $casts = ['is_active' => 'boolean'];

    /** A method flagged USD is the "pay in USD" option: its lines carry an exchange rate and a USD amount. */
    public function isUsd(): bool
    {
        return strtoupper((string) $this->currency) === 'USD';
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
