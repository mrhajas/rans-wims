<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configurable earning/deduction line types (Housing Allowance, Transport
 * Allowance, Overtime, etc.) — account-scoped config rows, looked up
 * directly by account_id like MaterialRequestWorkflowStep/
 * CaseTrackingNotificationRule, not through the App\Models\Model base's
 * global scope.
 */
class SalaryComponent extends Model
{
    public const TYPE_EARNING = 'earning';
    public const TYPE_DEDUCTION = 'deduction';

    protected $fillable = ['account_id', 'key', 'label', 'type', 'is_system', 'is_active', 'sort_order'];

    protected $casts = ['is_system' => 'boolean', 'is_active' => 'boolean'];

    /** Seeded on first use per account — Basic Salary/Allowances/Deductions mirror Staff's own flat fields, is_system so they can't be deleted. */
    public static function defaults(): array
    {
        return [
            ['key' => 'basic_salary', 'label' => 'Basic Salary', 'type' => self::TYPE_EARNING, 'is_system' => true, 'sort_order' => 0],
            ['key' => 'allowances', 'label' => 'Allowances', 'type' => self::TYPE_EARNING, 'is_system' => true, 'sort_order' => 1],
            ['key' => 'other_deductions', 'label' => 'Other Deductions', 'type' => self::TYPE_DEDUCTION, 'is_system' => true, 'sort_order' => 2],
        ];
    }

    public static function forAccount(int $accountId)
    {
        $existing = static::where('account_id', $accountId)->count();
        if ($existing === 0) {
            foreach (static::defaults() as $default) {
                static::create($default + ['account_id' => $accountId]);
            }
        }

        return static::where('account_id', $accountId)->orderBy('sort_order')->get();
    }
}
