<?php

namespace App\Policies;

use App\Models\MaterialReallocation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MaterialReallocationPolicy
{
    use HandlesAuthorization;

    public function create(User $user): bool
    {
        return $user->hasRole([...User::SITE_ENGINEER_ROLES, 'Super Admin', 'Admin'])
            || $user->can('create-material-reallocations');
    }

    public function view(User $user, MaterialReallocation $mrr): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers'])) {
            return true;
        }

        if ($user->hasRole(['Procurement Manager', 'Procurement Manger'])) {
            return true;
        }

        if ($user->hasRole('Store Manager')) {
            return true;
        }

        if ($user->hasRole(User::SITE_ENGINEER_ROLES)) {
            return (int) $mrr->user_id === (int) $user->id;
        }

        return false;
    }

    public function print(User $user, MaterialReallocation $mrr): bool
    {
        return $this->view($user, $mrr);
    }

    public function downloadPdf(User $user, MaterialReallocation $mrr): bool
    {
        return $this->print($user, $mrr);
    }

    public function update(User $user, MaterialReallocation $mrr): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return ! in_array($mrr->status, MaterialReallocation::lockedStatuses(), true);
        }

        if ($user->hasRole(User::SITE_ENGINEER_ROLES)) {
            return $mrr->canBeEditedBySiteEngineer() && (int) $mrr->user_id === (int) $user->id;
        }

        if ($user->hasRole(['Project Manager', 'Project Managers'])) {
            return $mrr->status === MaterialReallocation::STATUS_SUBMITTED;
        }

        return false;
    }

    public function delete(User $user, MaterialReallocation $mrr): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        if ($user->hasRole(User::SITE_ENGINEER_ROLES)) {
            return $this->update($user, $mrr);
        }

        return false;
    }

    public function submit(User $user, MaterialReallocation $mrr): bool
    {
        $isOwnerSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES) && (int) $mrr->user_id === (int) $user->id;

        return ($user->hasRole(['Super Admin', 'Admin']) || $isOwnerSiteEngineer)
            && in_array($mrr->status, [MaterialReallocation::STATUS_DRAFT, MaterialReallocation::STATUS_REQUEST_CHANGES], true);
    }

    public function clear(User $user, MaterialReallocation $mrr): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers'])
            && $mrr->status === MaterialReallocation::STATUS_SUBMITTED;
    }

    public function allocate(User $user, MaterialReallocation $mrr): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'Procurement Manager', 'Procurement Manger'])
            && $mrr->status === MaterialReallocation::STATUS_PM_CLEARANCE;
    }

    public function dispatch(User $user, MaterialReallocation $mrr): bool
    {
        $isOwnerSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES) && (int) $mrr->user_id === (int) $user->id;

        return ($user->hasRole(['Super Admin', 'Admin']) || $isOwnerSiteEngineer)
            && $mrr->status === MaterialReallocation::STATUS_WAREHOUSE_ALLOCATION;
    }

    public function receive(User $user, MaterialReallocation $mrr): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'Store Manager'])
            && $mrr->status === MaterialReallocation::STATUS_DISPATCH_PREPARATION;
    }

    public function finalApprove(User $user, MaterialReallocation $mrr): bool
    {
        return $user->hasRole(['Super Admin', 'Admin'])
            && $mrr->status === MaterialReallocation::STATUS_WAREHOUSE_RECEIVING;
    }

    public function requestChanges(User $user, MaterialReallocation $mrr): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers', 'Procurement Manager', 'Procurement Manger'])
            && in_array($mrr->status, [MaterialReallocation::STATUS_SUBMITTED, MaterialReallocation::STATUS_PM_CLEARANCE], true);
    }

    public function reject(User $user, MaterialReallocation $mrr): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers', 'Procurement Manager', 'Procurement Manger'])
            && in_array($mrr->status, [MaterialReallocation::STATUS_SUBMITTED, MaterialReallocation::STATUS_PM_CLEARANCE], true);
    }

    public function cancel(User $user, MaterialReallocation $mrr): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return ! in_array($mrr->status, MaterialReallocation::lockedStatuses(), true);
        }

        $isOwnerSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES) && (int) $mrr->user_id === (int) $user->id;

        return $isOwnerSiteEngineer && $mrr->canBeEditedBySiteEngineer();
    }
}
