<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Loading & Unloading Report: view, print / download (also the per-person statement) and Excel export. Super Admin gets them; others are granted in Roles. */
return new class extends Migration
{
    private const ABILITIES = [
        'read-transport-loading-reports',
        'print-transport-loading-reports',
        'export-transport-loading-reports',
    ];

    public function up(): void
    {
        foreach (self::ABILITIES as $ability) {
            Permission::query()->firstOrCreate(['name' => $ability, 'guard_name' => 'web']);
        }
        Role::where('name', 'Super Admin')->first()?->givePermissionTo(self::ABILITIES);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::ABILITIES)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
