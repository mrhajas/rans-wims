<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global Feature Control rows for the Opening Balances / Project Mapping and the Alerts pages of Subcontractor Management. The registry listed them but no migration
 * inserted them, so their routes were counted under the parent "subcontracts" feature. Safe to run twice; an existing row keeps its flags (only the descriptive columns are refreshed).
 */
return new class extends Migration
{
    private const FEATURES = ['subcontract_opening', 'subcontract_alerts'];

    public function up(): void
    {
        if (! Schema::hasTable('system_features')) {
            return;
        }
        foreach (self::FEATURES as $key) {
            $f = SystemFeatureRegistry::features()[$key] ?? null;
            if (! $f) {
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

    public function down(): void
    {
        if (Schema::hasTable('system_features')) {
            DB::table('system_features')->whereIn('key', self::FEATURES)->delete();
            FeatureManager::flushCache();
        }
    }
};
