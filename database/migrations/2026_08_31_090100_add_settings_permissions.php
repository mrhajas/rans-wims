<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Hardcoded rather than read from PermissionRegistry::modules() — see
     * 2026_08_30_090300_add_wims_connect_bulk_sms_permissions.php's docblock
     * for why a migration must not depend on the present-day registry.
     */
    private function abilities(): array
    {
        return ['read-settings', 'create-settings', 'update-settings'];
    }

    /**
     * SettingController's index()/store()/updateTerms() had no explicit
     * permission check of their own — access was entirely governed by these
     * auto-derived abilities, which never existed as real Permission rows,
     * so only Super Admin (via Gate::before's unconditional bypass) could
     * ever reach the Settings page. Granting to Super Admin only here
     * preserves that exact real-world behavior while making the permission
     * a real, assignable row instead of a silent gap.
     */
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
