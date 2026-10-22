<?php

namespace App\Support\Permissions;

use Spatie\Permission\PermissionRegistrar;

/**
 * Spatie's registrar that counts how many times the permission list was thrown away (a role, a permission or a person's access was changed).
 * App\Traits\FastPermissionChecks keeps its ready-made lists only for the current count.
 */
class TrackedPermissionRegistrar extends PermissionRegistrar
{
    public static int $generation = 0;

    public function forgetCachedPermissions()
    {
        self::$generation++;

        return parent::forgetCachedPermissions();
    }

    public function clearPermissionsCollection(): void
    {
        self::$generation++;
        parent::clearPermissionsCollection();
    }
}
