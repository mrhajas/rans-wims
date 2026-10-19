<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants 'verify-transport-records' to Super Admin only, by default — not
 * hardcoded to Super Admin in application code (TransportRecordController
 * checks $user->can('verify-transport-records') like every other ability
 * here), so a Super Admin can hand this permission to any other role
 * through the ordinary Roles & Permissions screen, exactly like every
 * other permission in this table.
 */
return new class extends Migration
{
    private function abilities(): array
    {
        return ['verify-transport-records'];
    }

    public function up(): void
    {
        $abilities = $this->abilities();

        foreach ($abilities as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::where('name', 'Super Admin')->first();
        if ($role) {
            $role->givePermissionTo($abilities);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->abilities())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
