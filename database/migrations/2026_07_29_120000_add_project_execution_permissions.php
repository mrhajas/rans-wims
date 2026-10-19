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
            'create-work-packages',
            'read-work-packages',
            'update-work-packages',
            'delete-work-packages',
            'approve-work-package-updates',
            'manage-project-execution-settings',
            'view-project-execution-analytics',
            'export-project-execution-reports',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        $roleGrants = [
            'Super Admin' => $permissions,
            'Admin' => [
                'create-work-packages',
                'read-work-packages',
                'update-work-packages',
                'delete-work-packages',
                'approve-work-package-updates',
                'view-project-execution-analytics',
                'export-project-execution-reports',
            ],
            'Project Manager' => [
                'create-work-packages',
                'read-work-packages',
                'update-work-packages',
                'approve-work-package-updates',
                'view-project-execution-analytics',
                'export-project-execution-reports',
            ],
            'Project Managers' => [
                'create-work-packages',
                'read-work-packages',
                'update-work-packages',
                'approve-work-package-updates',
                'view-project-execution-analytics',
                'export-project-execution-reports',
            ],
            'Site Engineers' => ['read-work-packages'],
            'Site Engineer' => ['read-work-packages'],
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
            'create-work-packages',
            'read-work-packages',
            'update-work-packages',
            'delete-work-packages',
            'approve-work-package-updates',
            'manage-project-execution-settings',
            'view-project-execution-analytics',
            'export-project-execution-reports',
        ])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
