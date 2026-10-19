<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';

        $permission = Permission::query()->firstOrCreate([
            'name' => 'read-material-requests',
            'guard_name' => $guard,
        ]);

        $storeManager = Role::query()->where('name', 'Store Manager')->first();

        if ($storeManager) {
            $storeManager->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $storeManager = Role::query()->where('name', 'Store Manager')->first();

        if ($storeManager && Permission::query()->where('name', 'read-material-requests')->exists()) {
            $storeManager->revokePermissionTo('read-material-requests');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
