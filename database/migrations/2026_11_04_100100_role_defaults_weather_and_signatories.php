<?php

use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Starting role permissions (all changeable afterwards on the Roles screen, nothing is hard-coded):
 *  - Weather: every role may view weather, forecasts and weather-related site-risk warnings for the projects its people can access.
 *  - Signatories: for now only Super Admin holds the Signatories permissions. They are taken off every other role until an administrator gives them out.
 */
return new class extends Migration
{
    public function up(): void
    {
        $weather = Permission::findOrCreate('read-weather', 'web');
        $signatories = collect(array_keys(PermissionRegistry::modules()['signatories']['abilities'] ?? []))
            ->map(fn ($name) => Permission::findOrCreate($name, 'web'));

        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if (! $role->hasPermissionTo($weather)) {
                $role->givePermissionTo($weather);
            }
            if ($role->name === 'Super Admin') {
                $role->givePermissionTo($signatories->all());
            } else {
                $role->revokePermissionTo($signatories->all());
            }
        }
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // role permissions are data an administrator edits on the Roles screen: not reverted
    }
};
