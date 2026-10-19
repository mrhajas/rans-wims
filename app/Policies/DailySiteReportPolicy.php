<?php

namespace App\Policies;

use App\Models\DailySiteReport;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DailySiteReportPolicy
{
    use HandlesAuthorization;

    public function create(User $user): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        if ($user->hasRole(User::SITE_ENGINEER_ROLES)) {
            return $user->warehouses()->exists();
        }

        return $user->can('create-daily-site-reports');
    }

    public function view(User $user, DailySiteReport $dailySiteReport): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        if ($user->hasRole(['Project Manager', 'Project Managers'])) {
            return $user->manages($dailySiteReport->warehouse_id);
        }

        if ($user->hasRole(User::SITE_ENGINEER_ROLES)) {
            return (int) $dailySiteReport->submitted_by === (int) $user->id;
        }

        return false;
    }

    public function update(User $user, DailySiteReport $dailySiteReport): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        if ($user->hasRole(User::SITE_ENGINEER_ROLES)) {
            return (int) $dailySiteReport->submitted_by === (int) $user->id
                && ! $dailySiteReport->isLockedForSiteEngineer();
        }

        return false;
    }

    public function delete(User $user, DailySiteReport $dailySiteReport): bool
    {
        return $this->update($user, $dailySiteReport);
    }

    public function download(User $user, DailySiteReport $dailySiteReport): bool
    {
        return $this->view($user, $dailySiteReport);
    }

    public function print(User $user, DailySiteReport $dailySiteReport): bool
    {
        return $this->view($user, $dailySiteReport);
    }

    public function archive(User $user, DailySiteReport $dailySiteReport): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        if ($user->hasRole(['Project Manager', 'Project Managers'])) {
            return $user->manages($dailySiteReport->warehouse_id);
        }

        return false;
    }
}
