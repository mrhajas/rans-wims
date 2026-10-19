<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'read-reports',
            'guard_name' => 'web',
        ]);

        foreach (['Procurement Manager', 'Procurement Manger'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['Procurement Manager', 'Procurement Manger'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role && $role->hasPermissionTo('read-reports')) {
                $role->revokePermissionTo('read-reports');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
