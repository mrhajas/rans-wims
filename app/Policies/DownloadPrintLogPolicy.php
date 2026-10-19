<?php

namespace App\Policies;

use App\Models\DownloadPrintLog;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DownloadPrintLogPolicy
{
    use HandlesAuthorization;

    protected function isSuperAdmin(User $user): bool
    {
        return $user->hasRole('Super Admin');
    }

    public function viewAny(User $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function view(User $user, DownloadPrintLog $log): bool
    {
        return $this->isSuperAdmin($user);
    }
}
