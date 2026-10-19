<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Hardcoded rather than read from PermissionRegistry::modules() — a
     * migration must not depend on the present-day registry. Adds to the
     * 5 abilities already created by 2026_09_30_090001_add_task_manager_permissions.
     */
    private function abilities(): array
    {
        return [
            'read-all-task-manager',
            'update-any-task-manager',
            'assign-multiple-task-manager',
            'reassign-task-manager',
            'change-status-task-manager',
            'change-priority-task-manager',
            'change-due-date-task-manager',
            'comment-task-manager',
            'attach-task-manager',
            'manage-checklist-task-manager',
            'view-history-task-manager',
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
        Permission::query()->whereIn('name', $this->abilities())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
