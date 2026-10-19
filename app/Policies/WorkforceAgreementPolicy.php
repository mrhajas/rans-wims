<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkforceAgreement;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Simple CRUD policy — no approval workflow of its own (creation by an
 * authorized user IS the authorization, per the request). The one real rule:
 * once a LabourListItem references an agreement, its terms are locked —
 * corrections go through a new agreement row, never an in-place edit,
 * mirroring how LabourList itself is locked once submitted.
 */
class WorkforceAgreementPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('read-workforce-agreements');
    }

    public function view(User $user, WorkforceAgreement $agreement): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('create-workforce-agreements');
    }

    public function update(User $user, WorkforceAgreement $agreement): bool
    {
        if ($agreement->isLocked() || $agreement->status !== WorkforceAgreement::STATUS_ACTIVE) {
            return false;
        }

        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('update-workforce-agreements');
    }

    public function cancel(User $user, WorkforceAgreement $agreement): bool
    {
        return $agreement->status === WorkforceAgreement::STATUS_ACTIVE
            && ($user->hasRole(['Super Admin', 'Admin']) || $user->can('update-workforce-agreements'));
    }
}
