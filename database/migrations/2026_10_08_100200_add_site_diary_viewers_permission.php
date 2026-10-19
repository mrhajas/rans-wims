<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** "See who has viewed a diary": the Viewed By column on the list and the viewers summary in the diary history. */
return new class extends Migration
{
    public function up(): void
    {
        $p = Permission::query()->firstOrCreate(['name' => 'view-site-diary-viewers', 'guard_name' => 'web']);
        $role = Role::where('name', 'Super Admin')->first();
        if ($role) {
            $role->givePermissionTo($p);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'view-site-diary-viewers')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
