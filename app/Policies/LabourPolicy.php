<?php

namespace App\Policies;

use App\Models\LabourList;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Access to Labour Lists is decided by the permissions assigned on the Roles screen (read-labour, create-labour,
 * update-labour, delete-labour, submit-labour, clear-labour, approve-labour, create-payment-voucher-from-labour,
 * print-labour), combined with the list's own status and, for editing, who created it. Super Admin passes every
 * permission check through Gate::before (and is still held to the status rules by its carve-out).
 */
class LabourPolicy
{
    use HandlesAuthorization;

    public function create(User $user): bool
    {
        return $user->can('create-labour');
    }

    public function viewAny(User $user): bool
    {
        return $user->can('read-labour');
    }

    /**
     * A Site Engineer only sees their own labour lists; everyone else who
     * holds read-labour (PM, Super Admin/Admin, Accountant) sees all of
     * them. Simpler than Material Request's configurable
     * MaterialRequestVisibilityRule table — Labour's chain is a fixed
     * sequence of named roles, not a per-account customizable audience.
     */
    public function view(User $user, LabourList $labourList): bool
    {
        if (! $user->can('read-labour')) {
            return false;
        }

        if ($this->isSiteEngineerOnly($user) && ! $user->hasRole(['Super Admin', 'Admin'])) {
            return (int) $labourList->created_by_user_id === (int) $user->id;
        }

        return true;
    }

    public static function isSiteEngineerOnly(User $user): bool
    {
        return $user->hasRole(User::SITE_ENGINEER_ROLES)
            && ! $user->hasRole(['Project Manager', 'Project Managers']);
    }

    /** Whoever holds the permission may act on their own lists; Super Admin and Admin on anyone's. */
    private function ownsOrOversees(User $user, LabourList $labourList): bool
    {
        return $user->hasRole(['Super Admin', 'Admin'])
            || (int) $labourList->created_by_user_id === (int) $user->id;
    }

    public function update(User $user, LabourList $labourList): bool
    {
        if (! in_array($labourList->status, LabourList::editableStatuses(), true)) {
            return false;
        }

        return $user->can('update-labour') && $this->ownsOrOversees($user, $labourList);
    }

    /** Deletion is intentionally Draft-only, even for Super Admin — mirrors PaymentVoucher's own delete rule exactly. */
    public function delete(User $user, LabourList $labourList): bool
    {
        if ($labourList->status !== LabourList::STATUS_DRAFT) {
            return false;
        }

        return $user->can('delete-labour') && $this->ownsOrOversees($user, $labourList);
    }

    /**
     * Every status $user could move this labour list to right now — the
     * single source of truth for both changeStatus() and the frontend's
     * available actions. Mirrors MaterialRequestPolicy::allowedTargetStatuses()
     * exactly. "Reject" isn't a separate status — a PM/Super Admin rejection
     * is just a Return with remarks, so both map to STATUS_RETURNED here.
     *
     * Clearing needs clear-labour and final approval needs approve-labour, and
     * each only at its own stage; Super Admin passes the permission checks but
     * not the current-status guard — Gate::before's LabourList carve-out (see
     * AuthServiceProvider) is what makes that unbypassable.
     */
    public function allowedTargetStatuses(User $user, LabourList $labourList): array
    {
        if ($labourList->status === LabourList::STATUS_SUBMITTED && $user->can('clear-labour')) {
            return [LabourList::STATUS_CLEARED, LabourList::STATUS_RETURNED];
        }

        if ($labourList->status === LabourList::STATUS_CLEARED && $user->can('approve-labour')) {
            return [LabourList::STATUS_APPROVED, LabourList::STATUS_RETURNED];
        }

        return [];
    }

    public function changeStatus(User $user, LabourList $labourList, ?string $targetStatus = null): bool
    {
        return in_array($targetStatus, $this->allowedTargetStatuses($user, $labourList), true);
    }

    /** Gates "Submit for Review" specifically — the Draft/Returned → Submitted transition, which isn't a status $user is choosing among (it's automatic), so it isn't part of allowedTargetStatuses(). */
    public function submit(User $user, LabourList $labourList): bool
    {
        return in_array($labourList->status, LabourList::editableStatuses(), true)
            && $user->can('submit-labour')
            && $this->ownsOrOversees($user, $labourList);
    }

    public function createPaymentVoucher(User $user, LabourList $labourList): bool
    {
        if ($labourList->status !== LabourList::STATUS_APPROVED || ! empty($labourList->payment_voucher_batch_id)) {
            return false;
        }

        return $user->can('create-payment-voucher-from-labour');
    }

    public function print(User $user, LabourList $labourList): bool
    {
        return $this->view($user, $labourList) && $user->can('print-labour');
    }
}
