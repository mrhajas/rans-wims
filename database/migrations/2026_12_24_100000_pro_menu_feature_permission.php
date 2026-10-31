<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** The Pro menu: one permission (every role has it by default; take it away on the Roles screen) and one Global Feature Control switch (on). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            $p = Permission::query()->firstOrCreate(['name' => 'use-pro-menu', 'guard_name' => 'web']);
            Role::query()->get()->each(fn ($r) => $r->givePermissionTo($p));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        if (Schema::hasTable('system_features') && ($f = SystemFeatureRegistry::features()['pro_menu'] ?? null)) {
            $exists = DB::table('system_features')->where('key', 'pro_menu')->exists();
            DB::table('system_features')->updateOrInsert(['key' => 'pro_menu'], [
                'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
            ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
            DB::table('system_features')->where('key', 'pro_menu')->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', 'use-pro-menu')->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'pro_menu')->delete();
        }
    }
};
