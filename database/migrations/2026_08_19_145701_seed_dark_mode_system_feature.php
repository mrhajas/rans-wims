<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the dark_mode row added to SystemFeatureRegistry after the
     * initial 2026_08_14_130100_seed_system_features migration already ran.
     */
    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['dark_mode'];
        $maxSortOrder = (int) DB::table('system_features')->max('sort_order');

        DB::table('system_features')->updateOrInsert(
            ['key' => 'dark_mode'],
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
        DB::table('system_features')->where('key', 'dark_mode')->delete();
    }
};
