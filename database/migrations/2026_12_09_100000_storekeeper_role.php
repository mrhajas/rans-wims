<?php

use App\Models\Role;
use App\Support\RoleBlueprints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Storekeeper role: the basics only. Everything every role gets (clock in and out, WIMSChat, own tasks and reminders, notices, view-only lookups of items,
 * warehouses, units, categories and contacts) plus view-only access to the stock records a storekeeper looks at (check-ins, check-outs, transfers,
 * material requests, delivery notes). Nothing can be created, changed, approved or deleted. It is not an "All warehouses" role: a Storekeeper is assigned the
 * warehouses they keep. Adds only; adjust it on the Roles screen.
 */
return new class extends Migration
{
    private const VIEW_ONLY = ['read-checkins', 'read-checkouts', 'read-transfers', 'read-material-requests', 'read-delivery-notes'];

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }
        $role = Role::withoutGlobalScopes()->where('name', 'Storekeeper')->where('guard_name', 'web')->first()
            ?: Role::create(['name' => 'Storekeeper', 'guard_name' => 'web', 'account_id' => 1]);
        $wanted = array_values(array_unique(array_merge(RoleBlueprints::EVERYONE, self::VIEW_ONLY)));
        $role->givePermissionTo(Permission::query()->where('guard_name', 'web')->whereIn('name', $wanted)->get()->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // the role is left in place (it may have users by now)
    }
};
