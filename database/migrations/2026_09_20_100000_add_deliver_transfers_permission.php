<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Default grant only — 'deliver-transfers' is not hardcoded to these roles
 * anywhere in application code (TransferController checks
 * $user->can('deliver-transfers') like every other ability here, with an
 * additional "own transfers only" restriction for anyone who isn't Super
 * Admin/Admin — see TransferController::userMayDeliver()), so a Super Admin
 * can reassign it to any other role, or grant it to specific users, from
 * the ordinary Roles & Permissions screen. whereIn('name', [...]) silently
 * skips a role name that doesn't exist on a given install rather than
 * failing the migration.
 *
 * 'Engineers' is this install's actual role for what the request called
 * "Site Engineer" — no role literally named "Site Engineer(s)" exists here,
 * but TransferController::denyExpiredSiteEngineerEdit() already treats
 * 'Engineers' (among other aliases) as that role for this exact module.
 */
return new class extends Migration
{
    private function abilities(): array
    {
        return ['deliver-transfers'];
    }

    public function up(): void
    {
        $abilities = $this->abilities();

        foreach ($abilities as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::whereIn('name', ['Super Admin', 'Admin', 'Engineers'])
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
