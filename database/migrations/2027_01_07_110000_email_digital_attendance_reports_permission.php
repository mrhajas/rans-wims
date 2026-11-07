<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Email Digital Attendance Reports": sending the attendance report by email, to the employee it is about or to typed addresses.
 * Every role that can already export attendance data keeps being able to share it, so it is granted to those roles and to Super Admin;
 * anyone else gets it on the Roles screen. Only ever adds; safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = 'email-digital-attendance-reports';
        Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);

        Role::query()->get()->each(function ($role) use ($name) {
            if ($role->name === 'Super Admin' || $role->hasPermissionTo('export-digital-attendance')) {
                $role->givePermissionTo($name);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // granted permissions are left in place
    }
};
