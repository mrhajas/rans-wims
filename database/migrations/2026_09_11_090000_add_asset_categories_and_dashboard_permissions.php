<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    // Asset Categories management previously piggybacked on the general
    // 'read-assets'/'create-assets' permissions (Authorizable::callAction()
    // derives its ability from the route name's first segment, and
    // assets.categories.* shares the 'assets' prefix with assets.index/
    // assets.create) — meaning anyone who could browse or register assets
    // could also manage categories, with no way to grant one without the
    // other. A real, standalone permission fixes that. Scoped to Super
    // Admin/Admin by default, matching how 'record-asset-appreciation' was
    // introduced.
    //
    // Asset Dashboard is the opposite case: it's meant to stay open to
    // everyone who already sees it today (its own permission check is
    // simply new, not a behavior change), so it's granted to every existing
    // role rather than a fixed list.
    public function up(): void
    {
        $manageCategories = Permission::query()->firstOrCreate(['name' => 'manage-asset-categories', 'guard_name' => 'web']);
        foreach (['Super Admin', 'Admin'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($manageCategories);
            }
        }

        $readDashboard = Permission::query()->firstOrCreate(['name' => 'read-asset-dashboard', 'guard_name' => 'web']);
        foreach (Role::all() as $role) {
            $role->givePermissionTo($readDashboard);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', ['manage-asset-categories', 'read-asset-dashboard'])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
