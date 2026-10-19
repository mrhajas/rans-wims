<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** The abilities of Simulation WIMS (the accounts page on the production side, the workspace pages inside the simulation). Run `php artisan simulation:sync` to copy new abilities into the simulation. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        foreach (['manage-simulation-accounts', 'read-simulation-import', 'run-simulation-import', 'read-simulation-provenance', 'manage-simulation-scenarios'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
    }
};
