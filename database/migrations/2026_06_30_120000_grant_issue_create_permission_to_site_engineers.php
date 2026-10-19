<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('create-issue-returns', 'web');
        Permission::findOrCreate('return-issue-returns', 'web');
        Permission::findOrCreate('read-issue-returns', 'web');

        $permissions = ['read-issue-returns', 'create-issue-returns', 'return-issue-returns'];

        foreach (['Site Engineers', 'Site Engineer'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['Site Engineers', 'Site Engineer'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $role->revokePermissionTo(['create-issue-returns']);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
