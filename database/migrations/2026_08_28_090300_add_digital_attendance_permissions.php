<?php

use App\Models\Role;
use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Every real staff-facing role can clock in/out and see their own
     * attendance — that's the baseline everyone needs. Project Manager also
     * gets team visibility (view-all + reports), mirroring the elevated
     * read access Compliance gave that role for the same "team lead" reason.
     */
    private function roleGrants(): array
    {
        $all = array_keys(PermissionRegistry::modules()['digital_attendance']['abilities']);
        $selfService = ['read-digital-attendance', 'clock-in-digital-attendance', 'clock-out-digital-attendance'];

        return [
            'Super Admin' => $all,
            'Admin' => $all,
            'Site Engineers' => $selfService,
            'Procurement Manager' => $selfService,
            'Store Manager' => $selfService,
            'Project Manager' => array_merge($selfService, ['read-all-digital-attendance', 'read-digital-attendance-reports']),
        ];
    }

    public function up(): void
    {
        $abilities = array_keys(PermissionRegistry::modules()['digital_attendance']['abilities']);

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
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['digital_attendance']['abilities']))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
