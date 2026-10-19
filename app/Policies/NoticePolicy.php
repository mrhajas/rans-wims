<?php

namespace App\Policies;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class NoticePolicy
{
    use HandlesAuthorization;

    public function create(User $user): bool
    {
        return $user->can('create-notices');
    }

    public function view(User $user, Notice $notice): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        if ((int) $notice->created_by === (int) $user->id || $notice->send_to_all) {
            return true;
        }

        $roleIds = $user->roles->pluck('id');

        return $notice->targetUsers()->where('users.id', $user->id)->exists()
            || $notice->targetRoles()->whereIn('roles.id', $roleIds)->exists();
    }
}
