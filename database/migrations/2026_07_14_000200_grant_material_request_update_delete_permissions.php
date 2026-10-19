<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            Permission::query()->firstOrCreate(['name' => 'update-material-requests', 'guard_name' => 'web']),
            Permission::query()->firstOrCreate(['name' => 'delete-material-requests', 'guard_name' => 'web']),
        ];

        foreach (['Project Manager', 'Project Managers', 'Admin', 'Super Admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if (! $role) {
                continue;
            }

            foreach ($permissions as $permission) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['Project Manager', 'Project Managers', 'Admin', 'Super Admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if (! $role) {
                continue;
            }

            foreach (['update-material-requests', 'delete-material-requests'] as $permissionName) {
                if ($role->hasPermissionTo($permissionName)) {
                    $role->revokePermissionTo($permissionName);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
