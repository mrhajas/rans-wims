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
            'name' => 'create-material-requests',
            'guard_name' => 'web',
        ]);

        foreach (['Project Manager', 'Project Managers'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['Project Manager', 'Project Managers'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role && $role->hasPermissionTo('create-material-requests')) {
                $role->revokePermissionTo('create-material-requests');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
