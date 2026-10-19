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

        $role = Role::query()->where('name', 'Super Admin')->first();
        if ($role && ! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'Super Admin')->first();
        if ($role && $role->hasPermissionTo('read-reports')) {
            $role->revokePermissionTo('read-reports');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
