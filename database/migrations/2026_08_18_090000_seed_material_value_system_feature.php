<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the material_value row added to SystemFeatureRegistry after the
     * initial 2026_08_14_130100_seed_system_features migration already ran.
     * sort_order 30 is higher than every existing row so it lands at the end
     * of the "Reports & Intelligence" module group without renumbering
     * anything else (module is the primary ORDER BY, sort_order only breaks
     * ties within it).
     */
    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['material_value'];

        DB::table('system_features')->updateOrInsert(
            ['key' => 'material_value'],
            [
                'name' => $feature['name'],
                'description' => $feature['description'],
                'module' => $feature['module'],
                'icon' => $feature['icon'],
                'is_enabled' => true,
                'sort_order' => 30,
                'route_prefixes' => json_encode($feature['route_prefixes']),
                'depends_on' => $feature['depends_on'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'material_value')->delete();
    }
};
