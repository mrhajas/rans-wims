<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    // The dashboard "Online Users" panel becomes a grantable permission. It starts switched on for every
    // role that exists today (new roles created later start without it, like any other permission).
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'view-online-users', 'guard_name' => 'web']);

        foreach (Role::all() as $role) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'view-online-users')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
