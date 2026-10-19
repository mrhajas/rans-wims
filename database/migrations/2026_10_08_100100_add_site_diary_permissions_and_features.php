<?php

use App\Models\Role;
use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Site Diary abilities (created for everyone to be granted from Roles & Permissions; only the Super Admin gets them
 * up front) and the Global Feature Control rows. Re-running is safe.
 */
return new class extends Migration
{
    private function abilities(): array
    {
        return [
            'read-site-diary',
            'view-all-site-diaries',
            'create-site-diary',
            'update-site-diary',
            'update-any-site-diary',
            'delete-site-diary',
            'delete-any-site-diary',
            'backdate-site-diary',
            'add-site-diary-photos',
            'delete-site-diary-photos',
            'add-site-diary-videos',
            'delete-site-diary-videos',
            'view-site-diary-photos',
            'view-site-diary-videos',
            'download-site-diary-media',
            'view-site-diary-history',
            'view-site-diary-analytics',
            'view-site-diary-reports',
            'print-site-diary-reports',
            'export-site-diary-reports',
            'manage-site-diary-settings',
        ];
    }

    public function up(): void
    {
        foreach ($this->abilities() as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role = Role::where('name', 'Super Admin')->first();
        if ($role) {
            $role->givePermissionTo($this->abilities());
        }

        $features = SystemFeatureRegistry::features();
        $order = (int) DB::table('system_features')->max('sort_order');
        foreach (['site_diary', 'site_diary_photos', 'site_diary_videos', 'site_diary_reports', 'site_diary_analytics'] as $key) {
            $f = $features[$key];
            DB::table('system_features')->updateOrInsert(['key' => $key], [
                'name' => $f['name'],
                'description' => $f['description'],
                'module' => $f['module'],
                'icon' => $f['icon'],
                'is_enabled' => true,
                'sort_order' => ++$order,
                'route_prefixes' => json_encode($f['route_prefixes']),
                'depends_on' => $f['depends_on'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        FeatureManager::flushCache();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', $this->abilities())->delete();
        DB::table('system_features')->whereIn('key', ['site_diary', 'site_diary_photos', 'site_diary_videos', 'site_diary_reports', 'site_diary_analytics'])->delete();
        FeatureManager::flushCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
