<?php

use App\Models\Role;
use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Default grants for the new "compliance_records" module. No "Compliance
     * Officer"/"Director" role exists yet, so escalation-tier delivery uses
     * each record's own escalation_recipient_user_id instead of a role.
     */
    private function roleGrants(): array
    {
        $all = array_keys(PermissionRegistry::modules()['compliance_records']['abilities']);

        return [
            'Super Admin' => $all,
            'Admin' => $all,
            'Project Manager' => ['read-compliance-records', 'create-compliance-records', 'update-compliance-records', 'assign-compliance-records', 'start-compliance-renewal', 'upload-compliance-documents'],
            'Procurement Manager' => ['read-compliance-records', 'view-compliance-financials'],
        ];
    }

    public function up(): void
    {
        $abilities = array_keys(PermissionRegistry::modules()['compliance_records']['abilities']);

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
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['compliance_records']['abilities']))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
