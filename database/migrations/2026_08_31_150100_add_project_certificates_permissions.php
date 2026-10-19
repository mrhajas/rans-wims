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
        return [
            'view-project-certificates', 'create-project-certificates', 'edit-project-certificates',
            'submit-project-certificates', 'approve-project-certificates', 'issue-project-certificates',
            'record-payment-project-certificates', 'view-financials-project-certificates',
            'export-project-certificates', 'manage-settings-project-certificates',
        ];
    }

    /**
     * Super Admin only by default — this is a brand-new module with no
     * existing "QS" role in the system yet. Super Admin creates/assigns a
     * Quantity Surveyor role via the Roles UI and grants whichever of these
     * abilities that role needs, same as every other module's rollout.
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
