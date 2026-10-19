<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Mirrors Adjustment's dedicated `print-adjustments` ability, separate from `read-super-adjustments`. */
    public function up(): void
    {
        Permission::query()->firstOrCreate(['name' => 'print-super-adjustments', 'guard_name' => 'web']);

        $role = Role::where('name', 'Super Admin')->first();
        if ($role) {
            $role->givePermissionTo('print-super-adjustments');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'print-super-adjustments')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
