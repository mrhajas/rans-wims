<?php

namespace App\Policies;

use App\Models\SalaryPeriod;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Access to salary periods is decided by the permissions assigned on the Roles screen (read, create / prepare-bulk,
 * update, submit, approve, pay) combined with the period's own status. There is no second "who does which step"
 * list any more.
 */
class SalaryPeriodPolicy
{
    use HandlesAuthorization;

    /** Creating a period belongs to whoever prepares salary (individual or bulk). */
    public function create(User $user): bool
    {
        return $user->can('create-salary-periods') || $user->can('prepare-bulk-salary-periods');
    }

    public function viewAny(User $user): bool
    {
        return $user->can('read-salary-periods');
    }

    public function view(User $user, SalaryPeriod $salaryPeriod): bool
    {
        return $user->can('read-salary-periods');
    }

    public function update(User $user, SalaryPeriod $salaryPeriod): bool
    {
        return $user->can('update-salary-periods')
            && in_array($salaryPeriod->status, [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING, SalaryPeriod::STATUS_REJECTED], true);
    }

    /**
     * Every status $user could move this period to right now — the single
     * source of truth for both changeStatus() below and the status
     * dropdown, mirroring MaterialRequestPolicy::allowedTargetStatuses()
     * exactly, including being consulted for Super Admin via the
     * Gate::before carve-out in AuthServiceProvider.
     */
    public function allowedTargetStatuses(User $user, SalaryPeriod $salaryPeriod): array
    {
        $canSubmit = $user->can('submit-salary-periods');
        $canApprove = $user->can('approve-salary-periods');

        return match ($salaryPeriod->status) {
            SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING => $canSubmit
                ? [SalaryPeriod::STATUS_PENDING_APPROVAL]
                : [],
            SalaryPeriod::STATUS_PENDING_APPROVAL => $canApprove
                ? [SalaryPeriod::STATUS_APPROVED, SalaryPeriod::STATUS_REJECTED]
                : [],
            SalaryPeriod::STATUS_REJECTED => $canSubmit
                ? [SalaryPeriod::STATUS_PENDING_APPROVAL]
                : [],
            SalaryPeriod::STATUS_APPROVED, SalaryPeriod::STATUS_PARTIALLY_PAID => $canApprove
                ? [SalaryPeriod::STATUS_CANCELLED]
                : [],
            default => [],
        };
    }

    public function changeStatus(User $user, SalaryPeriod $salaryPeriod, ?string $targetStatus = null): bool
    {
        return in_array($targetStatus, $this->allowedTargetStatuses($user, $salaryPeriod), true);
    }

    public function pay(User $user, SalaryPeriod $salaryPeriod): bool
    {
        return $user->can('pay-salary-periods')
            && in_array($salaryPeriod->status, [SalaryPeriod::STATUS_APPROVED, SalaryPeriod::STATUS_PARTIALLY_PAID], true);
    }
}
