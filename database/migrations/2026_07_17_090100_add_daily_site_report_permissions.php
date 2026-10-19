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
            'create-daily-site-reports',
            'read-daily-site-reports',
            'update-daily-site-reports',
            'delete-daily-site-reports',
            'archive-daily-site-reports',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => $guard,
            ]);
        }

        $roleGrants = [
            'Site Engineers' => ['create-daily-site-reports', 'read-daily-site-reports', 'update-daily-site-reports', 'delete-daily-site-reports'],
            'Project Manager' => ['read-daily-site-reports', 'archive-daily-site-reports'],
            'Admin' => ['create-daily-site-reports', 'read-daily-site-reports', 'archive-daily-site-reports'],
            'Super Admin' => $permissions,
        ];

        foreach ($roleGrants as $roleName => $grantedPermissions) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($grantedPermissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissions = [
            'create-daily-site-reports',
            'read-daily-site-reports',
            'update-daily-site-reports',
            'delete-daily-site-reports',
            'archive-daily-site-reports',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->where('name', $permission)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
