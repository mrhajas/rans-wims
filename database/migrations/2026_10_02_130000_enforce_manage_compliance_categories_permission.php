<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    // 'manage-compliance-categories' was registered in the permission list but never
    // enforced: ComplianceCategoryController rode on the shared read/create/update/
    // delete-compliance-records abilities (derived from the 'compliance-records' route
    // prefix), so anyone who could edit compliance records could also edit the shared
    // category taxonomy. Now that it is enforced, grant it to every role that could
    // already do this (update-compliance-records) so nobody loses access on deploy;
    // administrators can then narrow it from the Roles screen.
    public function up(): void
    {
        $manage = Permission::query()->firstOrCreate(['name' => 'manage-compliance-categories', 'guard_name' => 'web']);

        foreach (Role::all() as $role) {
            if ($role->hasPermissionTo('update-compliance-records') || in_array($role->name, ['Super Admin', 'Admin'], true)) {
                $role->givePermissionTo($manage);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
