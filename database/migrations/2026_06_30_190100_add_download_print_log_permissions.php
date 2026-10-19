<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Permission::query()->firstOrCreate([
            'name' => 'read-download-print-logs',
            'guard_name' => 'web',
        ]);

        Role::query()->where('name', 'Super Admin')->get()->each(function (Role $role) {
            $role->givePermissionTo('read-download-print-logs');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'read-download-print-logs')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
