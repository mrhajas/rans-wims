<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Mark Complete" — separate from "Change Task Status": that ability only
 * covers moving a task between the non-final columns (To Do/In
 * Progress/Review); actually completing a task (via the Mark Complete
 * button or dragging a card onto the Completed column) requires this one
 * instead. Unlike every other task-manager permission, this one is granted
 * to every existing role by default — completing your own/assigned work
 * is expected baseline behavior, not a privileged action.
 */
return new class extends Migration
{
    private function abilities(): array
    {
        return ['mark-complete-task-manager'];
    }

    public function up(): void
    {
        $abilities = $this->abilities();

        foreach ($abilities as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::all()->each(fn (Role $role) => $role->givePermissionTo($abilities));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->abilities())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
