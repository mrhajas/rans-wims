<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * database/migrations/2026_09_06_150000_restore_case_tracking_default_permissions.php
 * only granted 5 of the 16 registered case_tracking abilities (read/create/
 * update/comment/attach) to existing roles. The other 11 — including
 * assign, change-status, resolve, reopen, view-analytics, export, print,
 * manage-settings, read-all, update-any, delete — were never granted to ANY
 * role, not even Super Admin. Unlike most modules, CaseTicket is explicitly
 * carved out of the Gate::before blanket Super Admin bypass
 * (AuthServiceProvider — CaseTicketPolicy governs it directly), so without
 * this grant literally nobody could move a case past "Submitted",
 * assign it, or resolve it after launch.
 */
return new class extends Migration
{
    private function abilities(): array
    {
        return [
            'read-all-case-tracking',
            'update-any-case-tracking',
            'delete-case-tracking',
            'assign-case-tracking',
            'change-status-case-tracking',
            'resolve-case-tracking',
            'reopen-case-tracking',
            'view-analytics-case-tracking',
            'export-case-tracking',
            'print-case-tracking',
            'manage-case-settings',
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
        // Down deliberately does not delete the Permission rows here — they
        // may already be granted to other roles by the time this rolls
        // back, and 2026_09_06_150000's own down() already handles deleting
        // the case_tracking permission rows entirely.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
