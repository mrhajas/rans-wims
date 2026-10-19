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
            'read-material-requests',
            'create-material-requests',
            'update-material-requests',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => $guard,
            ]);
        }

        $siteEngineers = Role::query()->where('name', 'Site Engineers')->first();
        if ($siteEngineers) {
            $siteEngineers->givePermissionTo([
                'read-material-requests',
                'create-material-requests',
                'update-material-requests',
            ]);
        }

        $approverRoles = Role::query()
            ->whereIn('name', ['Project Manager', 'Procurement Manager', 'Admin', 'Super Admin'])
            ->get();

        foreach ($approverRoles as $role) {
            $role->givePermissionTo(['read-material-requests']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissions = [
            'read-material-requests',
            'create-material-requests',
            'update-material-requests',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->where('name', $permission)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
