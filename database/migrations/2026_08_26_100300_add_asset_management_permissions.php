<?php

use App\Models\Role;
use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Default grants for the new "assets" module. No "Fleet Manager"/"Asset
     * Manager" role exists yet, so access is scoped to the roles already
     * closest to asset movement/finance in their existing responsibilities —
     * an admin can rebalance via the Roles UI afterward.
     */
    private function roleGrants(): array
    {
        $all = array_keys(PermissionRegistry::modules()['assets']['abilities']);

        return [
            'Super Admin' => $all,
            'Admin' => $all,
            'Project Manager' => ['read-assets', 'assign-assets', 'check-out-assets', 'check-in-assets', 'transfer-assets', 'record-asset-maintenance', 'print-assets'],
            'Store Manager' => ['read-assets', 'check-out-assets', 'check-in-assets', 'transfer-assets', 'print-assets'],
            'Site Engineers' => ['read-assets', 'check-out-assets', 'check-in-assets'],
            'Procurement Manager' => ['read-assets', 'create-assets', 'update-assets', 'view-asset-financials'],
        ];
    }

    public function up(): void
    {
        $abilities = array_keys(PermissionRegistry::modules()['assets']['abilities']);

        foreach ($abilities as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach ($this->roleGrants() as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['assets']['abilities']))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
