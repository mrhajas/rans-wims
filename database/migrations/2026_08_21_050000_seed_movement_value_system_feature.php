<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the movement_value row added to SystemFeatureRegistry, following
     * the same updateOrInsert pattern as
     * 2026_08_18_090000_seed_material_value_system_feature. sort_order 31
     * lands it right after Material Value in the "Reports & Intelligence"
     * group.
     */
    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['movement_value'];

        DB::table('system_features')->updateOrInsert(
            ['key' => 'movement_value'],
            [
                'name' => $feature['name'],
                'description' => $feature['description'],
                'module' => $feature['module'],
                'icon' => $feature['icon'],
                'is_enabled' => true,
                'sort_order' => 31,
                'route_prefixes' => json_encode($feature['route_prefixes']),
                'depends_on' => $feature['depends_on'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'movement_value')->delete();
    }
};
