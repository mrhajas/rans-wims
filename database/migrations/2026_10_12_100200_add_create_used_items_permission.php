<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Create USED copy of an Item" (create-used-items) is its own permission under Items. Everyone who can already create
 * items keeps being able to use it, so nothing disappears for anyone; it can then be switched per role in Roles.
 * Only adds; safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'create-used-items', 'guard_name' => 'web']);

        Role::query()->get()->each(function ($role) use ($permission) {
            if ($role->name === 'Super Admin' || $role->hasPermissionTo('create-items')) {
                $role->givePermissionTo($permission);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Granted permissions are left in place.
    }
};
