<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Role::query()
            ->where('name', 'Project Manager')
            ->get()
            ->each(function (Role $role) {
                $role->givePermissionTo('create-notices');
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()
            ->where('name', 'Project Manager')
            ->get()
            ->each(function (Role $role) {
                $role->revokePermissionTo('create-notices');
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
