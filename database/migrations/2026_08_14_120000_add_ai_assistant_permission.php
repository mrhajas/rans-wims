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
        $permission = 'use-ai-assistant';

        Permission::query()->firstOrCreate([
            'name' => $permission,
            'guard_name' => $guard,
        ]);

        $roles = [
            'Super Admin',
            'Admin',
            'Project Manager',
            'Site Engineers',
            'Procurement Manager',
            'Store Manager',
        ];

        foreach ($roles as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'use-ai-assistant')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
