<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The steps of the Project Certificate workflow. Who may perform a step is no longer kept in
 * project_certificate_workflow_steps: it is the permission mapped to the step in STEP_PERMISSIONS, assigned on the Roles
 * screen like every other access. The table is kept (old data), but nothing reads or edits it any more.
 */
class ProjectCertificateWorkflowStep extends Model
{
    protected $fillable = ['account_id', 'step_key', 'label', 'description', 'role_names', 'user_ids'];

    protected $casts = [
        'role_names' => 'array',
        'user_ids' => 'array',
    ];

    public const STEP_CREATE = 'create';
    public const STEP_SUBMIT = 'submit';
    public const STEP_APPROVE = 'approve';
    public const STEP_ISSUE = 'issue';
    public const STEP_RECORD_PAYMENT = 'record_payment';

    /** The permission that stands for each step. */
    public const STEP_PERMISSIONS = [
        self::STEP_CREATE => 'create-project-certificates',
        self::STEP_SUBMIT => 'submit-project-certificates',
        self::STEP_APPROVE => 'approve-project-certificates',
        self::STEP_ISSUE => 'issue-project-certificates',
        self::STEP_RECORD_PAYMENT => 'record-payment-project-certificates',
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
