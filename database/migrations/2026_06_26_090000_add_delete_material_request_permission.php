<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'delete-material-requests',
            'guard_name' => 'web',
        ]);

        $siteEngineers = Role::query()->where('name', 'Site Engineers')->first();
        if ($siteEngineers) {
            $siteEngineers->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'delete-material-requests')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
