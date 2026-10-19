<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the task_manager row added to SystemFeatureRegistry after the
     * initial 2026_08_14_130100_seed_system_features migration already ran —
     * same pattern as 2026_08_19_145701_seed_dark_mode_system_feature.
     */
    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['task_manager'];
        $maxSortOrder = (int) DB::table('system_features')->max('sort_order');

        DB::table('system_features')->updateOrInsert(
            ['key' => 'task_manager'],
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
        DB::table('system_features')->where('key', 'task_manager')->delete();
    }
};
