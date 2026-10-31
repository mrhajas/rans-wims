<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Users > Reset Password: one permission, given to Super Admin only (assign it to other roles on the Roles screen). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            $p = Permission::query()->firstOrCreate(['name' => 'reset-password-users', 'guard_name' => 'web']);
            Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($p));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'reset-password-users')->delete();
    }
};
