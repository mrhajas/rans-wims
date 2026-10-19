<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Hardcoded rather than read from PermissionRegistry::modules() — a
     * migration must not depend on the present-day registry.
     */
    private function abilities(): array
    {
        return [
            'read-transport-dashboard',

            'read-transport-vehicles',
            'create-transport-vehicles',
            'update-transport-vehicles',
            'delete-transport-vehicles',

            'read-transport-records',
            'create-transport-records',
            'update-transport-records',
            'delete-transport-records',
            'override-transport-amount',
            'mark-paid-transport-records',
            'create-payment-voucher-from-transport',

            'read-transport-locations',
            'create-transport-locations',
            'update-transport-locations',
            'delete-transport-locations',

            'print-transport-records',
            'export-transport-records',

            'manage-transport-settings',
        ];
    }

    /** Granted to Super Admin only, matching every other new-module permission migration in this codebase. */
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
