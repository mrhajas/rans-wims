<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    // Contacts gets Print / Download. Every role that can already view Contacts keeps seeing the new buttons;
    // roles without read-contacts (and roles created later) start without it.
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => 'print-contacts', 'guard_name' => 'web']);

        foreach (Role::all() as $role) {
            if ($role->permissions->contains('name', 'read-contacts')) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'print-contacts')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
