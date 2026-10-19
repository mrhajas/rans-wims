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

        $permissions = [
            'read-notices',
            'create-notices',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => $guard,
            ]);
        }

        Role::query()->get()->each(function (Role $role) {
            $role->givePermissionTo('read-notices');
        });

        Role::query()->whereIn('name', ['Admin', 'Super Admin'])->get()->each(function (Role $role) {
            $role->givePermissionTo('create-notices');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissions = [
            'read-notices',
            'create-notices',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->where('name', $permission)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
