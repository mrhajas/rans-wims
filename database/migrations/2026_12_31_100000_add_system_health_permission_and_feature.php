<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * System Health: the permission (nobody has it by default, so only the Super Admin sees the page until a role is given it) and its Global Feature Control row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            Permission::query()->firstOrCreate(['name' => 'read-system-health', 'guard_name' => 'web']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (Schema::hasTable('system_features') && ($f = SystemFeatureRegistry::features()['system_health'] ?? null)) {
            $exists = DB::table('system_features')->where('key', 'system_health')->exists();
            DB::table('system_features')->updateOrInsert(['key' => 'system_health'], [
                'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
            ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
            DB::table('system_features')->where('key', 'system_health')->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'read-system-health')->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'system_health')->delete();
        }
    }
};
