<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The steps of the Labour List chain. Who may perform a step is no longer kept in labour_workflow_steps: it is the
 * permission mapped to the step in STEP_PERMISSIONS, assigned on the Roles screen like every other access. The table is
 * kept (old data), but nothing reads or edits it any more.
 */
class LabourWorkflowStep extends Model
{
    protected $fillable = ['account_id', 'step_key', 'label', 'description', 'role_names', 'user_ids'];

    protected $casts = [
        'role_names' => 'array',
        'user_ids' => 'array',
    ];

    public const STEP_SUBMIT = 'submit';
    public const STEP_PM_CLEAR = 'pm_clear';
    public const STEP_SUPER_ADMIN_APPROVE = 'super_admin_approve';
    public const STEP_CREATE_PAYMENT_VOUCHER = 'create_payment_voucher';
    public const STEP_VIEW = 'view';
    public const STEP_PRINT = 'print';

    /** The permission that stands for each step. */
    public const STEP_PERMISSIONS = [
        self::STEP_SUBMIT => 'submit-labour',
        self::STEP_PM_CLEAR => 'clear-labour',
        self::STEP_SUPER_ADMIN_APPROVE => 'approve-labour',
        self::STEP_CREATE_PAYMENT_VOUCHER => 'create-payment-voucher-from-labour',
        self::STEP_VIEW => 'read-labour',
        self::STEP_PRINT => 'print-labour',
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
