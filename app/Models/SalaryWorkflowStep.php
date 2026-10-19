<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The steps of the Salary workflow. Who may perform a step is no longer kept in salary_workflow_steps: it is the
 * permission mapped to the step in STEP_PERMISSIONS, assigned on the Roles screen like every other access. The table is
 * kept (old data), but nothing reads or edits it any more.
 */
class SalaryWorkflowStep extends Model
{
    protected $fillable = ['account_id', 'step_key', 'label', 'description', 'role_names', 'user_ids'];

    protected $casts = ['role_names' => 'array', 'user_ids' => 'array'];

    public const STEP_PREPARE = 'prepare';
    public const STEP_APPROVE = 'approve';
    public const STEP_PAY = 'pay';

    /** The permission that stands for each step (Prepare: creating a period or preparing salary; Submit has its own permission). */
    public const STEP_PERMISSIONS = [
        self::STEP_PREPARE => 'create-salary-periods',
        self::STEP_APPROVE => 'approve-salary-periods',
        self::STEP_PAY => 'pay-salary-periods',
    ];

    public static function permissionFor(string $stepKey): ?string
    {
        return self::STEP_PERMISSIONS[$stepKey] ?? null;
    }

    /** Does $user hold the permission of $stepKey? (Super Admin holds every permission through Gate::before.) */
    public static function isAllowed(User $user, string $stepKey): bool
    {
        $permission = self::permissionFor($stepKey);

        return $permission !== null && $user->can($permission);
    }

    /** Every active user holding the permission of $stepKey, for "it is your turn" notices. */
    public static function assignedUsers(int $accountId, string $stepKey): Collection
    {
        $permission = self::permissionFor($stepKey);
        if ($permission === null) {
            return new Collection();
        }

        return User::permission($permission)->where('account_id', $accountId)->where('is_active', true)->get();
    }
}
