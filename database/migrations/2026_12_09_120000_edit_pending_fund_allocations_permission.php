<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** "Edit an Allocation that is Pending Approval": a funds allocation sheet that waits for approval can be edited by whoever holds this permission. The Super Admin holds it for now; give it to other roles on the Roles screen. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $p = Permission::query()->firstOrCreate(['name' => 'edit-pending-fund-allocations', 'guard_name' => 'web']);
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($p));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'edit-pending-fund-allocations')->delete();
    }
};
