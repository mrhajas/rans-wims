<?php

use App\Services\FeatureManager;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Accounts Tracking: its abilities (Roles screen) and its Global Feature Control rows. The financial tables themselves are in their own schema. */
return new class extends Migration
{
    public function up(): void
    {
        $names = array_keys(PermissionRegistry::modules()['accounts_tracking']['abilities'] ?? []);
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        $all = Permission::query()->whereIn('name', $names)->get();
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo($all->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            foreach (SystemFeatureRegistry::features() as $key => $f) {
                if (! str_starts_with($key, 'acct_') && $key !== 'accounts_tracking') {
                    continue;
                }
                $exists = DB::table('system_features')->where('key', $key)->exists();
                DB::table('system_features')->updateOrInsert(['key' => $key], [
                    'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
                ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
                DB::table('system_features')->where('key', $key)->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
            }
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(PermissionRegistry::modules()['accounts_tracking']['abilities'] ?? []))->delete();
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where(fn ($q) => $q->where('key', 'like', 'acct\_%')->orWhere('key', 'accounts_tracking'))->delete();
            FeatureManager::flushCache();
        }
    }
};
