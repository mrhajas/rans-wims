<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the "Dashboard Cards" ability (Profile > Dashboard cards: choose the shortcut cards on your own dashboard) and gives it to EVERY
 * existing role. It never opens a module: the dashboard only ever shows cards for modules the person can already open. It stays a normal
 * ability on the Roles screen, so it can be switched off for any role there. New roles get it when they are created (RoleController::store).
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'customize-dashboard-cards', 'guard_name' => 'web']);
        Role::query()->get()->each(fn ($role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'customize-dashboard-cards')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
