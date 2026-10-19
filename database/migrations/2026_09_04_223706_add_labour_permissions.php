<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Hardcoded rather than read from PermissionRegistry::modules() — a
     * migration must not depend on the present-day registry, which could
     * later change/rename entries and silently corrupt this historical grant.
     */
    private function abilities(): array
    {
        return [
            'read-labour',
            'create-labour',
            'update-labour',
            'delete-labour',
            'submit-labour',
            'clear-labour',
            'approve-labour',
            'create-payment-voucher-from-labour',
            'print-labour',
            // Payment Voucher's own new "Record Payment" action (payment
            // completion, layered additively on top of Authorised — see the
            // 2026_09_04_223705 migration) — bundled into this migration
            // since it ships as part of the same Labour integration feature.
            'record-payment-payment-vouchers',
        ];
    }

    /**
     * Granted to Super Admin only, matching every other new-module
     * permission migration in this codebase. "Accountant" does not exist as
     * a role yet — RANS must create it via the existing Role management
     * screen and grant it create-payment-voucher-from-labour plus Payment
     * Voucher's own existing create-/update-/print-payment-vouchers
     * abilities; "Site Engineer(s)"/"Project Manager(s)" already exist and
     * should be granted their stage-specific abilities the same way, post-deploy.
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
