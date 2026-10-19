<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';

        $permissions = [
            'read-work-package-daily-updates',
            'create-work-package-daily-updates',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        $roleGrants = [
            'Super Admin' => $permissions,
            'Admin' => $permissions,
            'Project Manager' => $permissions,
            'Project Managers' => $permissions,
            'Site Engineers' => $permissions,
            'Site Engineer' => $permissions,
        ];

        foreach ($roleGrants as $roleName => $granted) {
            $role = Role::query()->where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            foreach ($granted as $permission) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', [
            'read-work-package-daily-updates',
            'create-work-package-daily-updates',
        ])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
