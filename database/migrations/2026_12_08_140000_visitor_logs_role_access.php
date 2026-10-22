<?php

use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who can use Visitor Logs: Super Admin, Admin and Project Manager have every Visitor Logs permission; Receptionist and Engineers can log visitors and incoming mail & deliveries
 * (register, check out, hand over, reports of their own projects) but not correct, cancel, see sensitive details or change settings. Adds only: nothing a role already has is taken away.
 */
return new class extends Migration
{
    private const LOGGING = [
        'read-visitor-logs', 'create-visitor-logs', 'update-visitor-logs', 'checkout-visitor-logs', 'view-active-visitors', 'read-visitors', 'create-visitors', 'update-visitors', 'view-visitor-history',
        'read-incoming-items', 'create-incoming-items', 'update-incoming-items', 'update-incoming-item-status', 'handover-incoming-items', 'attach-incoming-items', 'view-incoming-item-history',
        'read-visitor-reports', 'print-visitor-reports',
    ];

    public function up(): void
    {
        $all = array_keys(PermissionRegistry::modules()['visitor_logs']['abilities'] ?? []);
        $grant = function (string $role, array $names) {
            foreach (Role::withoutGlobalScopes()->where('name', $role)->where('guard_name', 'web')->get() as $r) {
                $r->givePermissionTo(Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->get()->all());
            }
        };
        foreach (['Super Admin', 'Admin', 'Project Manager'] as $role) {
            $grant($role, $all);
        }
        foreach (['Receptionist', 'Engineers'] as $role) {
            $grant($role, self::LOGGING);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // granted permissions are left in place
    }
};
