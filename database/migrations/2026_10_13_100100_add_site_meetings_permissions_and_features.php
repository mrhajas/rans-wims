<?php

use App\Models\Role;
use App\Services\FeatureManager;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Site Meetings abilities and Global Feature Control rows, plus sensible starting access: Admin and Project Manager get
 * everything, Engineers (who write the minutes) can open, write and print, the other site-facing roles can view and print.
 * Only adds, so anything tuned by hand is kept. Re-running is safe.
 */
return new class extends Migration
{
    private const FEATURES = ['site_meetings', 'site_meetings_reports', 'site_meetings_reminders'];

    private function abilities(): array
    {
        return array_keys(PermissionRegistry::modules()['site_meetings']['abilities']);
    }

    public function up(): void
    {
        $all = $this->abilities();
        foreach ($all as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $grants = [
            'Super Admin' => $all,
            'Admin' => $all,
            'Project Manager' => $all,
            'Engineers' => ['read-site-meetings', 'update-site-meeting-reports', 'print-site-meetings'],
            'Procurement Manager' => ['read-site-meetings', 'print-site-meetings'],
            'Store Manager' => ['read-site-meetings', 'print-site-meetings'],
            'Quantity Surveyor (QS)' => ['read-site-meetings', 'print-site-meetings'],
        ];
        foreach ($grants as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->get()->each(fn ($role) => $role->givePermissionTo($permissions));
        }

        $features = SystemFeatureRegistry::features();
        $order = (int) DB::table('system_features')->max('sort_order');
        foreach (self::FEATURES as $key) {
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
        DB::table('system_features')->whereIn('key', self::FEATURES)->delete();
        FeatureManager::flushCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
