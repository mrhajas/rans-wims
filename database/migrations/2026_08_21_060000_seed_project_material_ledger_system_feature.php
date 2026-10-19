<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the project_material_ledger row added to SystemFeatureRegistry,
     * following the same updateOrInsert pattern as
     * 2026_08_21_050000_seed_movement_value_system_feature. sort_order 32
     * lands it right after Movement Value in "Reports & Intelligence".
     */
    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['project_material_ledger'];

        DB::table('system_features')->updateOrInsert(
            ['key' => 'project_material_ledger'],
            [
                'name' => $feature['name'],
                'description' => $feature['description'],
                'module' => $feature['module'],
                'icon' => $feature['icon'],
                'is_enabled' => true,
                'sort_order' => 32,
                'route_prefixes' => json_encode($feature['route_prefixes']),
                'depends_on' => $feature['depends_on'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'project_material_ledger')->delete();
    }
};
