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

/**
 * Subcontract Completion and Contract Payment Requests: the abilities (Super Admin only until a role is granted them on the Roles page) and the Global Feature Control row
 * `subcontract_claims` (child of `subcontracts`, route prefix `subcontracts.claims.`). Safe to run twice; an existing feature row keeps its on / off flag.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['read-subcontract-claims', 'create-subcontract-claims', 'submit-subcontract-claims', 'review-subcontract-claims', 'decide-subcontract-claims', 'verify-subcontract-completion'];

    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            foreach (self::PERMISSIONS as $name) {
                Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
            if (Schema::hasTable('roles')) {
                Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo(Permission::query()->whereIn('name', self::PERMISSIONS)->get()->all()));
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        if (! Schema::hasTable('system_features')) {
            return;
        }
        $key = 'subcontract_claims';
        $f = SystemFeatureRegistry::features()[$key] ?? null;
        if (! $f) {
            return;
        }
        $exists = DB::table('system_features')->where('key', $key)->exists();
        DB::table('system_features')->updateOrInsert(['key' => $key], [
            'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
            'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
        ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
        DB::table('system_features')->where('key', $key)->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
        FeatureManager::flushCache();
    }

    public function down(): void
    {
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->where('key', 'subcontract_claims')->delete();
            FeatureManager::flushCache();
        }
    }
};
