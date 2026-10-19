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
        return ['bypass-offline-restrictions'];
    }

    /**
     * Only Super Admin by default — $can() already treats Super Admin as an
     * unconditional override regardless of this grant, so this just keeps
     * their own ability list accurate in the Roles UI. Everyone else stays
     * blocked while offline until a Super Admin grants this explicitly.
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
