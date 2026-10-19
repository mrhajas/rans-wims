<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Global" — gates the Team Manager "Created By" filter (and the broader
 * everyone's-tasks view it unlocks). Super Admin already sees this filter
 * via the hardcoded Super-Admin/Admin bypass in TaskManagerController, so
 * this permission is what lets another role or specific user in on it
 * without making them a Super Admin.
 */
return new class extends Migration
{
    private function abilities(): array
    {
        return ['global-task-manager'];
    }

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
