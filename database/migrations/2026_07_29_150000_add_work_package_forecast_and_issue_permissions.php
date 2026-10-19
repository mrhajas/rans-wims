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
            'create-work-package-material-forecasts',
            'delete-work-package-material-forecasts',
            'create-work-package-labour-forecasts',
            'delete-work-package-labour-forecasts',
            'create-work-package-equipment-forecasts',
            'delete-work-package-equipment-forecasts',
            'read-work-package-issues',
            'create-work-package-issues',
            'update-work-package-issues',
            'delete-work-package-issues',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        $forecastCreate = [
            'create-work-package-material-forecasts',
            'create-work-package-labour-forecasts',
            'create-work-package-equipment-forecasts',
        ];
        $forecastDelete = [
            'delete-work-package-material-forecasts',
            'delete-work-package-labour-forecasts',
            'delete-work-package-equipment-forecasts',
        ];

        $roleGrants = [
            'Super Admin' => $permissions,
            'Admin' => $permissions,
            'Project Manager' => array_merge($forecastCreate, $forecastDelete, [
                'read-work-package-issues', 'create-work-package-issues', 'update-work-package-issues',
            ]),
            'Project Managers' => array_merge($forecastCreate, $forecastDelete, [
                'read-work-package-issues', 'create-work-package-issues', 'update-work-package-issues',
            ]),
            'Site Engineers' => array_merge($forecastCreate, [
                'read-work-package-issues', 'create-work-package-issues',
            ]),
            'Site Engineer' => array_merge($forecastCreate, [
                'read-work-package-issues', 'create-work-package-issues',
            ]),
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
            'create-work-package-material-forecasts',
            'delete-work-package-material-forecasts',
            'create-work-package-labour-forecasts',
            'delete-work-package-labour-forecasts',
            'create-work-package-equipment-forecasts',
            'delete-work-package-equipment-forecasts',
            'read-work-package-issues',
            'create-work-package-issues',
            'update-work-package-issues',
            'delete-work-package-issues',
        ])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
