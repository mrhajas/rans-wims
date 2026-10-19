<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    // Gate Passes get their own permissions, introduced the same way as Delivery Notes' so the feature
    // is usable on day one without widening anyone's reach: Super Admin/Admin get all three, and every
    // other role inherits from what it can already do with the parent records —
    //   view/print  <- print-transfers or print-checkins
    //   generate    <- update-transfers or update-checkins
    public function up(): void
    {
        $read = Permission::query()->firstOrCreate(['name' => 'read-gate-passes', 'guard_name' => 'web']);
        $create = Permission::query()->firstOrCreate(['name' => 'create-gate-passes', 'guard_name' => 'web']);
        $print = Permission::query()->firstOrCreate(['name' => 'print-gate-passes', 'guard_name' => 'web']);

        foreach (Role::all() as $role) {
            if (in_array($role->name, ['Super Admin', 'Admin'], true)) {
                $role->givePermissionTo([$read, $create, $print]);

                continue;
            }

            if ($role->permissions->contains('name', 'print-transfers') || $role->permissions->contains('name', 'print-checkins')) {
                $role->givePermissionTo([$read, $print]);
            }

            if ($role->permissions->contains('name', 'update-transfers') || $role->permissions->contains('name', 'update-checkins')) {
                $role->givePermissionTo($create);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', ['read-gate-passes', 'create-gate-passes', 'print-gate-passes'])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
