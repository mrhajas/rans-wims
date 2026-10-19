<?php

namespace App\Support;

use App\Models\User;

/**
 * The role name an approval is signed off under ("Super Admin", "Admin", …). Frozen onto the record when it is approved so
 * the record keeps showing it even if the person's roles change later. Super Admin wins when someone has several roles.
 */
class ApproverRole
{
    public static function for(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $names = $user->getRoleNames();

        return $names->contains('Super Admin') ? 'Super Admin' : ($names->first() ?: null);
    }
}
