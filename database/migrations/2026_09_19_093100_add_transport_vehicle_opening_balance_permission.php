<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Default grant only — 'manage-transport-vehicle-opening-balance' is not
 * hardcoded to these roles anywhere in application code (TransportVehicle
 * Controller checks $user->can(...) like every other ability here), so a
 * Super Admin can reassign it to any other role from the ordinary Roles &
 * Permissions screen. whereIn('name', [...]) silently skips a role name
 * that doesn't exist on a given install rather than failing the migration.
 */
return new class extends Migration
{
    private function abilities(): array
    {
        return ['manage-transport-vehicle-opening-balance'];
    }

    public function up(): void
    {
        $abilities = $this->abilities();

        foreach ($abilities as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::whereIn('name', ['Super Admin', 'Procurement Manager', 'Store Manager'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($abilities));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->abilities())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
