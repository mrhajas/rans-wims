<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Signatories: Super Admin may verify and activate their own signature too (listed on the Roles screen, so it can be given to others). */
return new class extends Migration
{
    public function up(): void
    {
        $p = Permission::findOrCreate('verify-own-signatures', 'web');
        Role::query()->where('name', 'Super Admin')->where('guard_name', 'web')->get()->each(fn ($r) => $r->givePermissionTo($p));
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'verify-own-signatures')->delete();
    }
};
