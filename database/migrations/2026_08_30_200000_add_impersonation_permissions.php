<?php

use App\Models\Role;
use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Only Super Admin by default — reproduces today's exact behaviour
     * (impersonation was hard Super-Admin-only, ImpersonationController
     * checked hasRole('Super Admin') everywhere, Admin never had access).
     * Nothing changes for anyone until a Super Admin grants these to
     * another role from Roles & Permissions.
     */
    public function up(): void
    {
        $abilities = array_keys(PermissionRegistry::modules()['impersonation']['abilities']);

        foreach ($abilities as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::where('name', 'Super Admin')->first();
        if ($role) {
            $role->givePermissionTo($abilities);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['impersonation']['abilities']))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
