<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings Global Feature Control back in step with the code registry: adds any registered function that has no row (enabled), and adds the
 * route prefixes that three existing functions were missing (their workflow pages), so those pages obey the same switch as the rest of
 * the function. Nothing is removed or switched off, and an existing row's on/off state is never touched.
 */
return new class extends Migration {
    private const PREFIXES = [
        'executive_report' => ['executive-reports.'],
        'transport_management' => ['transport.'],
        'low_stock_alert' => ['stock-alerts.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('system_features')) {
            return;
        }
        $order = (int) DB::table('system_features')->max('sort_order');
        $registry = SystemFeatureRegistry::features();
        foreach ($registry as $key => $f) {
            if (DB::table('system_features')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('system_features')->insert([
                'key' => $key, 'name' => $f['name'], 'description' => $f['description'] ?? null, 'module' => $f['module'] ?? null, 'icon' => $f['icon'] ?? null,
                'is_enabled' => 1, 'depends_on' => $f['depends_on'] ?? null, 'route_prefixes' => json_encode($f['route_prefixes'] ?? []),
                'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (self::PREFIXES as $key => $add) {
            $row = DB::table('system_features')->where('key', $key)->first();
            if (! $row) {
                continue;
            }
            $have = json_decode($row->route_prefixes ?: '[]', true) ?: [];
            $merged = array_values(array_unique(array_merge($have, $add)));
            if ($merged !== $have) {
                DB::table('system_features')->where('id', $row->id)->update(['route_prefixes' => json_encode($merged), 'updated_at' => now()]);
            }
        }
        FeatureManager::flushCache();
    }

    public function down(): void
    {
        // Rows are kept: removing a registered function's switch would silently re-enable anything it had switched off.
    }
};
