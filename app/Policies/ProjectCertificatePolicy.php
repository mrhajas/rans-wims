<?php

namespace App\Policies;

use App\Models\ProjectCertificate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Combines the Spatie permissions assigned on the Roles screen (who can see and who can act at each stage) with the
 * certificate's own status machine. There is no second "who does which step" list any more.
 */
class ProjectCertificatePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view-project-certificates');
    }

    public function view(User $user, ProjectCertificate $certificate): bool
    {
        return $user->can('view-project-certificates');
    }

    public function create(User $user): bool
    {
        return $user->can('create-project-certificates');
    }

    public function update(User $user, ProjectCertificate $certificate): bool
    {
        if (! $user->can('edit-project-certificates')) {
            return false;
        }

        return $certificate->status === ProjectCertificate::STATUS_DRAFT;
    }

    public function submit(User $user, ProjectCertificate $certificate): bool
    {
        return $user->can('submit-project-certificates')
            && $certificate->status === ProjectCertificate::STATUS_DRAFT;
    }

    public function approve(User $user, ProjectCertificate $certificate): bool
    {
        return $user->can('approve-project-certificates')
            && $certificate->status === ProjectCertificate::STATUS_SUBMITTED;
    }

    public function issue(User $user, ProjectCertificate $certificate): bool
    {
        return $user->can('issue-project-certificates')
            && $certificate->status === ProjectCertificate::STATUS_APPROVED;
    }

    public function revise(User $user, ProjectCertificate $certificate): bool
    {
        // Reopening an issued certificate is an approver's act: Edit AND Approve.
        return $user->can('edit-project-certificates')
            && $user->can('approve-project-certificates')
            && $certificate->status === ProjectCertificate::STATUS_ISSUED;
    }

    public function recordPayment(User $user, ProjectCertificate $certificate): bool
    {
        return $user->can('record-payment-project-certificates')
            && $certificate->status === ProjectCertificate::STATUS_ISSUED;
    }

    /**
     * Editing an already-recorded payment (rather than recording a new one)
     * is deliberately NOT permission-configurable like every other ability
     * here — a payment is a financial record other people may already be
     * relying on, so only Super Admin can correct one, mirroring
     * SupplierLedgerController::destroyOpeningBalance()'s explicit
     * hasRole('Super Admin') gate. The route itself is additionally behind
     * password.confirm, and the frontend re-confirms the user's password
     * inline before even opening the edit form.
     */
    public function editPayment(User $user, ProjectCertificate $certificate): bool
    {
        return $user->hasRole('Super Admin') && $certificate->status === ProjectCertificate::STATUS_ISSUED;
    }

    public function viewFinancials(User $user, ProjectCertificate $certificate): bool
    {
        return $user->can('view-financials-project-certificates');
    }

    public function export(User $user, ProjectCertificate $certificate): bool
    {
        return $user->can('export-project-certificates');
    }

    public function cancel(User $user, ProjectCertificate $certificate): bool
    {
        // Cancelling is an approver's act too: Edit AND Approve.
        return $user->can('edit-project-certificates')
            && $user->can('approve-project-certificates')
            && ! in_array($certificate->status, [ProjectCertificate::STATUS_ISSUED, ProjectCertificate::STATUS_CANCELLED], true);
    }
}
