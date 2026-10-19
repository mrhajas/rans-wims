<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * "manage-prayer-times": opens the Prayer Times settings page (menu entry and the link in the top-bar prayer drawer).
 * Only Super Admin has it; give it to a role in the Roles page to let that role's people in. Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $p = Permission::query()->firstOrCreate(['name' => 'manage-prayer-times', 'guard_name' => 'web']);
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($role) => $role->givePermissionTo($p));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // nothing to undo
    }
};
