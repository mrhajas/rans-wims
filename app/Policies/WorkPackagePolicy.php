<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkPackage;
use Illuminate\Auth\Access\HandlesAuthorization;

class WorkPackagePolicy
{
    use HandlesAuthorization;

    protected function isPlanner(User $user): bool
    {
        return $user->hasRole(['Admin', 'Project Manager', 'Project Managers']);
    }

    protected function isSiteEngineer(User $user): bool
    {
        return $user->hasRole(User::SITE_ENGINEER_ROLES);
    }

    public function viewAny(User $user): bool
    {
        return $this->isPlanner($user) || $this->isSiteEngineer($user);
    }

    public function view(User $user, WorkPackage $workPackage): bool
    {
        if ($this->isPlanner($user)) {
            return true;
        }

        return $this->isSiteEngineer($user) && $workPackage->isAssignedTo($user);
    }

    public function create(User $user): bool
    {
        return $this->isPlanner($user);
    }

    public function update(User $user, WorkPackage $workPackage): bool
    {
        return $this->isPlanner($user);
    }

    public function submitUpdate(User $user, WorkPackage $workPackage): bool
    {
        return $this->isSiteEngineer($user) && $workPackage->isAssignedTo($user);
    }

    public function approveUpdate(User $user): bool
    {
        return $this->isPlanner($user);
    }

    public function manageForecast(User $user, WorkPackage $workPackage): bool
    {
        return $this->isPlanner($user) || ($this->isSiteEngineer($user) && $workPackage->isAssignedTo($user));
    }

    public function manageAttachments(User $user, WorkPackage $workPackage): bool
    {
        return $this->isPlanner($user) || ($this->isSiteEngineer($user) && $workPackage->isAssignedTo($user));
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    public function restore(User $user): bool
    {
        return false;
    }
}
