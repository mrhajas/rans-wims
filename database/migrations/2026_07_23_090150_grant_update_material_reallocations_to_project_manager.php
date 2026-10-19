<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->where('name', 'Project Manager')->first();

        if ($role && ! $role->hasPermissionTo('update-material-reallocations')) {
            $role->givePermissionTo('update-material-reallocations');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'Project Manager')->first();

        if ($role && $role->hasPermissionTo('update-material-reallocations')) {
            $role->revokePermissionTo('update-material-reallocations');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
