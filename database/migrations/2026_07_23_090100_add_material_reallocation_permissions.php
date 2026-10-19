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
            'create-material-reallocations',
            'read-material-reallocations',
            'update-material-reallocations',
            'delete-material-reallocations',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => $guard,
            ]);
        }

        $roleGrants = [
            'Site Engineers' => $permissions,
            'Project Manager' => ['read-material-reallocations'],
            'Procurement Manager' => ['read-material-reallocations'],
            'Store Manager' => ['read-material-reallocations'],
            'Admin' => $permissions,
            'Super Admin' => $permissions,
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
        $permissions = [
            'create-material-reallocations',
            'read-material-reallocations',
            'update-material-reallocations',
            'delete-material-reallocations',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->where('name', $permission)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
