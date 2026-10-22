<?php

use App\Support\AllWarehouseRoles;
use App\Support\RoleBlueprints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1. "All warehouses" on a role (roles.all_warehouses): everyone with the role is assigned every project / warehouse, also the ones created later.
 * 2. The office roles Accounts, Chief Accountant, Cashier, Administration and Receptionist with a first set of permissions (App\Support\RoleBlueprints).
 *    A role that already exists keeps what it has; only missing permissions are added. Adjust them on the Roles screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles') && ! Schema::hasColumn('roles', 'all_warehouses')) {
            Schema::table('roles', fn (Blueprint $t) => $t->boolean('all_warehouses')->default(false));
        }
        if (Schema::hasTable('permissions')) {
            RoleBlueprints::apply(1);
            foreach (\App\Models\Role::withoutGlobalScopes()->where('all_warehouses', true)->get() as $role) {
                AllWarehouseRoles::syncRoleUsers($role);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'all_warehouses')) {
            Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('all_warehouses'));
        }
        // the roles themselves are left in place (they may have users by now)
    }
};
