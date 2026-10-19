<?php

namespace App\Policies;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LoginLogPolicy
{
    use HandlesAuthorization;

    protected function isAdmin(User $user): bool
    {
        return $user->hasRole(['Admin', 'Super Admin']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) && $user->can('read-login-logs');
    }

    public function view(User $user, LoginLog $loginLog): bool
    {
        return $this->isAdmin($user) && $user->can('read-login-logs');
    }

    public function export(User $user): bool
    {
        return $this->isAdmin($user) && $user->can('export-login-logs');
    }

    public function print(User $user): bool
    {
        return $this->isAdmin($user) && $user->can('print-login-logs');
    }
}
