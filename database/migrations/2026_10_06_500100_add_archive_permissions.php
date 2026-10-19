<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'read-archive', 'create-archive', 'update-archive', 'delete-archive',
        'preview-archive-files', 'download-archive-files', 'print-archive',
        'read-archive-confidential', 'view-archive-access-logs', 'view-archive-storage',
    ];

    // New permissions start with nobody but the Super Admin; grant them per role from Roles & Permissions.
    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
