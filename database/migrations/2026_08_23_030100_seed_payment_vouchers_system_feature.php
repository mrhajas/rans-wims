<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the payment_vouchers row added to SystemFeatureRegistry, following
     * the same updateOrInsert pattern as every other feature seed (e.g.
     * 2026_08_22_035836_seed_supplier_records_system_feature).
     */
    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['payment_vouchers'];
        $maxSortOrder = (int) DB::table('system_features')->max('sort_order');

        DB::table('system_features')->updateOrInsert(
            ['key' => 'payment_vouchers'],
            [
                'name' => $feature['name'],
                'description' => $feature['description'],
                'module' => $feature['module'],
                'icon' => $feature['icon'],
                'is_enabled' => true,
                'sort_order' => $maxSortOrder + 1,
                'route_prefixes' => json_encode($feature['route_prefixes']),
                'depends_on' => $feature['depends_on'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'payment_vouchers')->delete();
    }
};
