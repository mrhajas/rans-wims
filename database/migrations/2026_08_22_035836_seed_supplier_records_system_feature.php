<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the supplier_records row added to SystemFeatureRegistry, following
     * the same updateOrInsert pattern as every other feature seed (e.g.
     * 2026_08_21_060000_seed_project_material_ledger_system_feature). This
     * only registers the toggle in the pre-existing system_features table —
     * it creates no tables of its own. The module itself is a UI preview on
     * mock/session data pending schema approval.
     */
    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['supplier_records'];

        DB::table('system_features')->updateOrInsert(
            ['key' => 'supplier_records'],
            [
                'name' => $feature['name'],
                'description' => $feature['description'],
                'module' => $feature['module'],
                'icon' => $feature['icon'],
                'is_enabled' => true,
                'sort_order' => 33,
                'route_prefixes' => json_encode($feature['route_prefixes']),
                'depends_on' => $feature['depends_on'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'supplier_records')->delete();
    }
};
