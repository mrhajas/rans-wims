<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds system_features from SystemFeatureRegistry. Every feature starts
     * enabled — shipping this never takes anything away on day one, it only
     * gives Super Admins the switchboard to turn things off going forward.
     */
    public function up(): void
    {
        $sortOrder = 0;

        foreach (SystemFeatureRegistry::features() as $key => $feature) {
            DB::table('system_features')->updateOrInsert(
                ['key' => $key],
                [
                    'name' => $feature['name'],
                    'description' => $feature['description'],
                    'module' => $feature['module'],
                    'icon' => $feature['icon'],
                    'is_enabled' => true,
                    'sort_order' => $sortOrder,
                    'route_prefixes' => json_encode($feature['route_prefixes']),
                    'depends_on' => $feature['depends_on'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $sortOrder++;
        }
    }

    public function down(): void
    {
        DB::table('system_features')->whereIn('key', SystemFeatureRegistry::allFeatureKeys())->delete();
    }
};
