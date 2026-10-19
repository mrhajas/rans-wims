<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** The abilities of the subcontract opening balances and project mapping (the Super Admin always has them; other roles are granted on the Roles page). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        foreach (['read-opening-subcontracts', 'manage-opening-subcontracts', 'reset-opening-subcontracts', 'map-projects-subcontracts'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
    }
};
