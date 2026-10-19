<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the `edit-supplier-ledger` permission (see PermissionRegistry) —
 * lets a role correct an already-recorded Opening Balance instead of the
 * previous hardcoded `hasRole('Super Admin')` check. Granted only to Super
 * Admin here as the default, matching prior behavior, but it's a real
 * permission now: reassignable from the Role/Permission matrix like any
 * other ability, not hardcoded in the controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'edit-supplier-ledger',
            'guard_name' => 'web',
        ]);

        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin) {
            $superAdmin->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'edit-supplier-ledger')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
