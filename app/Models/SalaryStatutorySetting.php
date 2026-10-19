<?php

namespace App\Models;

class SalaryStatutorySetting extends Model
{
    public static $hasAccount = false;

    protected $fillable = ['account_id', 'is_enabled', 'paye_bands', 'nssf_employee_rate', 'nssf_employer_rate', 'wcf_rate', 'sdl_rate', 'updated_by_user_id'];

    public $casts = [
        'is_enabled' => 'boolean',
        'paye_bands' => 'array',
        'nssf_employee_rate' => 'float',
        'nssf_employer_rate' => 'float',
        'wcf_rate' => 'float',
        'sdl_rate' => 'float',
    ];

    /**
     * Current published Tanzania Mainland monthly PAYE bands — verify
     * against the latest Finance Act before relying on this in production;
     * tax law changes, and this module has no automatic way to know that.
     */
    public static function defaultPayeBands(): array
    {
        return [
            ['up_to' => 270000, 'rate' => 0.00],
            ['up_to' => 520000, 'rate' => 0.08],
            ['up_to' => 760000, 'rate' => 0.20],
            ['up_to' => 1000000, 'rate' => 0.25],
            ['up_to' => null, 'rate' => 0.30],
        ];
    }

    /** A transient (unsaved) default instance, used whenever an account has never touched these settings. */
    public static function defaultFor(int $accountId): self
    {
        return new self([
            'account_id' => $accountId,
            'is_enabled' => true,
            'paye_bands' => self::defaultPayeBands(),
            'nssf_employee_rate' => 0.10,
            'nssf_employer_rate' => 0.10,
            'wcf_rate' => 0.005,
            'sdl_rate' => 0.035,
        ]);
    }

    public static function forAccount(int $accountId): self
    {
        return static::where('account_id', $accountId)->first() ?? self::defaultFor($accountId);
    }

    public static function isEnabledFor(int $accountId): bool
    {
        return self::forAccount($accountId)->is_enabled;
    }
}
