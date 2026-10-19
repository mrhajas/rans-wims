<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkPackageIssue;
use Illuminate\Auth\Access\HandlesAuthorization;

class WorkPackageIssuePolicy
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

    public function view(User $user, WorkPackageIssue $issue): bool
    {
        if ($this->isPlanner($user)) {
            return true;
        }

        return $this->isSiteEngineer($user) && $issue->workPackage->isAssignedTo($user);
    }

    public function create(User $user, $workPackage): bool
    {
        if ($this->isPlanner($user)) {
            return true;
        }

        return $this->isSiteEngineer($user) && $workPackage->isAssignedTo($user);
    }

    public function update(User $user): bool
    {
        return $this->isPlanner($user);
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('Admin');
    }
}
