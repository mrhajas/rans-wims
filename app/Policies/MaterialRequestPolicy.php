<?php

namespace App\Policies;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestWorkflowStep;
use App\Models\User;
use App\Support\MaterialRequestVisibility;
use Illuminate\Auth\Access\HandlesAuthorization;

class MaterialRequestPolicy
{
    use HandlesAuthorization;

    /**
     * Every ability below is a PERMISSION assigned on the Roles screen (see MaterialRequestWorkflowStep::STEP_PERMISSIONS
     * for the step -> permission map and MaterialRequestVisibility for who sees what). Super Admin passes every
     * permission through the global Gate::before.
     */
    public function create(User $user): bool
    {
        // the form is open to whoever can raise a normal request or a quick one (the Store request then checks which of the two it is)
        return MaterialRequestWorkflowStep::isAllowed($user, MaterialRequestWorkflowStep::STEP_CREATE) || $this->createQuick($user);
    }

    /** The Quick path: a shorter request with its own approver (Roles > Material Requests > Raise Quick Material Requests). */
    public function createQuick(User $user): bool
    {
        return $user->can(MaterialRequestWorkflowStep::PERMISSION_QUICK_CREATE);
    }

    /** The hard gate for reaching the Material Requests list at all: "View" or "View all" (see MaterialRequestVisibility). */
    public function viewAny(User $user): bool
    {
        return MaterialRequestVisibility::hasAnyAccess($user);
    }

    public function view(User $user, MaterialRequest $materialRequest): bool
    {
        return MaterialRequestVisibility::isVisible($user, $materialRequest);
    }

    /**
     * Whoever holds "Edit Any Material Request" can edit any request that is not cleared and that Procurement has not
     * started on (saving the edit form rebuilds the item list, which would wipe procurement procedures and delivery
     * ticks). Otherwise, editing your OWN still-Pending-Approval request is an extension of the Create step: whoever
     * can create requests can edit their own draft before it has been decided on.
     */
    public function update(User $user, MaterialRequest $materialRequest): bool
    {
        if ($user->can('update-material-requests')) {
            return ! $materialRequest->isFinal()
                && ! in_array($materialRequest->status, [MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED], true)
                && ! $materialRequest->items()->whereNotNull('procedure')->exists();
        }

        return ($materialRequest->quick ? $this->createQuick($user) : MaterialRequestWorkflowStep::isAllowed($user, MaterialRequestWorkflowStep::STEP_CREATE))
            && $materialRequest->status === MaterialRequest::STATUS_PENDING_APPROVAL
            && (int) $materialRequest->user_id === (int) $user->id;
    }

    public function delete(User $user, MaterialRequest $materialRequest): bool
    {
        if ($user->can('delete-material-requests')) {
            return ! $materialRequest->isFinal();
        }

        return $this->update($user, $materialRequest);
    }

    /**
     * Every status $user could move this request to right now — the single source of truth for both changeStatus()
     * below and the buttons at the top of the request page (MaterialRequestResource exposes this list directly, so
     * the UI never offers a button the backend would reject). Each transition belongs to one workflow step
     * permission; Super Admin passes every step but, like everybody else, can only follow the real sequence:
     *
     *   Pending Approval -> Approved | Rejected                 (Approve / Reject step)
     *   Approved         -> Partially Issued | Issued           (Issue step)
     *   Partially Issued -> Partially Issued | Issued           (Issue step; Partially again = another batch left)
     *   Issued           -> Cleared                             (Clear / Complete step)
     *   Cleared / Rejected / Closed -> nothing (final)
     */
    public function allowedTargetStatuses(User $user, MaterialRequest $materialRequest): array
    {
        $can = fn (string $step) => MaterialRequestWorkflowStep::isAllowed($user, $step);

        return match ($materialRequest->status) {
            // a Quick request is approved by the Quick approvers only; a normal one by the normal approvers (Super Admin holds both permissions)
            MaterialRequest::STATUS_PENDING_APPROVAL => ($materialRequest->quick ? $user->can(MaterialRequestWorkflowStep::PERMISSION_QUICK_APPROVE) : $can(MaterialRequestWorkflowStep::STEP_APPROVE))
                ? [MaterialRequest::STATUS_APPROVED, MaterialRequest::STATUS_REJECTED]
                : [],
            MaterialRequest::STATUS_APPROVED => $can(MaterialRequestWorkflowStep::STEP_ISSUE)
                ? [MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED]
                : [],
            MaterialRequest::STATUS_PARTIALLY_ISSUED => $can(MaterialRequestWorkflowStep::STEP_ISSUE)
                ? [MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED]
                : [],
            MaterialRequest::STATUS_ISSUED => $can(MaterialRequestWorkflowStep::STEP_COMPLETE)
                ? [MaterialRequest::STATUS_CLEARED]
                : [],
            default => [],
        };
    }

    /** Procurement step: set / change the procedure of each item (only while the request is live, never once cleared). */
    public function setProcedures(User $user, MaterialRequest $materialRequest): bool
    {
        return in_array($materialRequest->status, MaterialRequest::procedureEligibleStatuses(), true)
            && MaterialRequestWorkflowStep::isAllowed($user, MaterialRequestWorkflowStep::STEP_CLEAR);
    }

    /**
     * Site step: tick items Partially / Fully Delivered and leave remarks. Needs the Receive permission AND, unless the
     * user is an administrator, an assignment to the request's project / warehouse / site. Only while the request
     * is Partially / Fully Issued; once it is Cleared everything is locked.
     */
    public function confirmDelivery(User $user, MaterialRequest $materialRequest): bool
    {
        if (! in_array($materialRequest->status, MaterialRequest::deliveryEligibleStatuses(), true)) {
            return false;
        }
        if (! MaterialRequestWorkflowStep::isAllowed($user, MaterialRequestWorkflowStep::STEP_RECEIVE)) {
            return false;
        }

        return $user->hasRole(['Super Admin', 'Admin'])
            || $user->warehouses()->where('warehouses.id', $materialRequest->warehouse_id)->exists();
    }

    /** Who can move a request to $targetStatus specifically — see allowedTargetStatuses() above. */
    public function changeStatus(User $user, MaterialRequest $materialRequest, ?string $targetStatus = null): bool
    {
        return in_array($targetStatus, $this->allowedTargetStatuses($user, $materialRequest), true);
    }

    public function print(User $user, MaterialRequest $materialRequest): bool
    {
        return $this->view($user, $materialRequest)
            && MaterialRequestWorkflowStep::isAllowed($user, MaterialRequestWorkflowStep::STEP_PRINT);
    }

    public function downloadPdf(User $user, MaterialRequest $materialRequest): bool
    {
        return $this->view($user, $materialRequest)
            && MaterialRequestWorkflowStep::isAllowed($user, MaterialRequestWorkflowStep::STEP_DOWNLOAD);
    }
}
