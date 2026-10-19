<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Grants the 26 permissions created by `permissions:sync` to the 6 real roles,
     * matching exactly what each role can already do today via hardcoded hasRole()
     * checks scattered across policies/controllers. This is purely additive data —
     * no application code reads these grants yet, so it's safe to ship standalone.
     */
    public function up(): void
    {
        $guard = 'web';

        $newPermissions = [
            'submit-material-reallocations',
            'clear-material-reallocations',
            'allocate-material-reallocations',
            'dispatch-material-reallocations',
            'receive-material-reallocations',
            'final-approve-material-reallocations',
            'request-changes-material-reallocations',
            'reject-material-reallocations',
            'cancel-material-reallocations',
            'print-material-reallocations',
            'download-pdf-material-reallocations',
            'change-status-material-requests',
            'print-material-requests',
            'manage-backups',
            'reset-stock',
            'read-item-price-history',
            'read-item-category-report',
            'manage-warehouse-email-subscribers',
            'manage-deleted-records',
            'manage-my-warehouses',
            'bypass-system-lockdown',
            'approve-adjustments',
            'bypass-transaction-edit-window',
            'warehouse-cleanup',
            'manage-account-settings',
            'read-site-report-compliance',
        ];

        foreach ($newPermissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        $roleGrants = [
            'Super Admin' => $newPermissions,

            'Admin' => [
                'submit-material-reallocations',
                'clear-material-reallocations',
                'allocate-material-reallocations',
                'dispatch-material-reallocations',
                'receive-material-reallocations',
                'final-approve-material-reallocations',
                'request-changes-material-reallocations',
                'reject-material-reallocations',
                'cancel-material-reallocations',
                'print-material-reallocations',
                'download-pdf-material-reallocations',
                'change-status-material-requests',
                'print-material-requests',
                'read-item-price-history',
                'read-item-category-report',
                'manage-warehouse-email-subscribers',
                'manage-deleted-records',
                'bypass-system-lockdown',
                'approve-adjustments',
                'bypass-transaction-edit-window',
                'warehouse-cleanup',
                'manage-account-settings',
                'read-site-report-compliance',
            ],

            // Site Engineers: only own-record material-reallocation actions + My Warehouses.
            'Site Engineers' => [
                'submit-material-reallocations',
                'dispatch-material-reallocations',
                'cancel-material-reallocations',
                'print-material-reallocations',
                'download-pdf-material-reallocations',
                'manage-my-warehouses',
            ],

            'Procurement Manager' => [
                'allocate-material-reallocations',
                'request-changes-material-reallocations',
                'reject-material-reallocations',
                'print-material-reallocations',
                'download-pdf-material-reallocations',
                'print-material-requests',
                'read-item-price-history',
                'read-item-category-report',
            ],

            // Project Manager: also gets bypass-system-lockdown and
            // bypass-transaction-edit-window — today's CheckinController/
            // CheckoutController/TransferController edit-window checks list
            // Site Engineers/Procurement Manager/Store Manager as *restricted*
            // roles and never mention Project Manager, meaning PM is implicitly
            // unrestricted already. Omitting PM from this grant would newly
            // restrict Project Managers the moment the controllers are converted.
            'Project Manager' => [
                'clear-material-reallocations',
                'request-changes-material-reallocations',
                'reject-material-reallocations',
                'print-material-reallocations',
                'download-pdf-material-reallocations',
                'print-material-requests',
                'read-item-price-history',
                'read-item-category-report',
                'bypass-system-lockdown',
                'bypass-transaction-edit-window',
                'read-site-report-compliance',
            ],

            'Store Manager' => [
                'receive-material-reallocations',
                'print-material-reallocations',
                'download-pdf-material-reallocations',
                'print-material-requests',
                'read-item-category-report',
            ],
        ];

        foreach ($roleGrants as $roleName => $granted) {
            $role = Role::query()->where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            foreach ($granted as $permission) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $newPermissions = [
            'submit-material-reallocations',
            'clear-material-reallocations',
            'allocate-material-reallocations',
            'dispatch-material-reallocations',
            'receive-material-reallocations',
            'final-approve-material-reallocations',
            'request-changes-material-reallocations',
            'reject-material-reallocations',
            'cancel-material-reallocations',
            'print-material-reallocations',
            'download-pdf-material-reallocations',
            'change-status-material-requests',
            'print-material-requests',
            'manage-backups',
            'reset-stock',
            'read-item-price-history',
            'read-item-category-report',
            'manage-warehouse-email-subscribers',
            'manage-deleted-records',
            'manage-my-warehouses',
            'bypass-system-lockdown',
            'approve-adjustments',
            'bypass-transaction-edit-window',
            'warehouse-cleanup',
            'manage-account-settings',
            'read-site-report-compliance',
        ];

        foreach (Role::all() as $role) {
            $role->revokePermissionTo($newPermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
