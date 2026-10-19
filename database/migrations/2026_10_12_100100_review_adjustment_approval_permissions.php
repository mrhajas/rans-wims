<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adjustment approval is now driven by permissions instead of hardcoded roles:
 *  - "Approve / Reject Adjustments" (approve-adjustments): Admin keeps it (it used to be hardcoded) and Project Manager gets it,
 *    together with what a Project Manager needs to raise adjustments of their own (view, create, print). Everything anyone
 *    creates still starts as Pending — approving your own record is a separate, recorded step.
 *  - "Approve / Reject Super Adjustments" (approve-super-adjustments): new, only Super Admin has it until granted in Roles.
 * Changing a record's status and editing an approved / rejected record stay Super Admin only. Only ever adds; safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['approve-adjustments', 'approve-super-adjustments', 'read-adjustments', 'create-adjustments', 'print-adjustments'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $grants = [
            'Super Admin' => ['approve-adjustments', 'approve-super-adjustments'],
            'Admin' => ['approve-adjustments', 'read-adjustments'],
            'Project Manager' => ['read-adjustments', 'create-adjustments', 'approve-adjustments', 'print-adjustments'],
        ];
        foreach ($grants as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->get()->each(fn ($role) => $role->givePermissionTo($permissions));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Granted permissions are left in place: removing them could take approval away from people who rely on it.
    }
};
