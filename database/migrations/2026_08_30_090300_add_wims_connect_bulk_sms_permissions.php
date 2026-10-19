<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Hardcoded rather than read from PermissionRegistry::modules() — a
     * migration is a historical snapshot and must not depend on the
     * present-day registry, which later renamed this exact module key to
     * 'wims_connect_bulk_connect' (see
     * 2026_08_30_100000_rename_bulk_sms_to_bulk_connect_permissions.php).
     * Reading the live registry here broke replaying this migration from a
     * clean database once that rename shipped.
     */
    private function abilities(): array
    {
        return ['read-wims-connect-bulk-sms', 'send-wims-connect-bulk-sms', 'print-wims-connect-bulk-sms'];
    }

    public function up(): void
    {
        $abilities = $this->abilities();

        foreach ($abilities as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (['Super Admin', 'Admin'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo($abilities);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->abilities())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
