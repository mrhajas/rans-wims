<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['task_reminders'];
        $maxSortOrder = (int) DB::table('system_features')->max('sort_order');

        DB::table('system_features')->updateOrInsert(
            ['key' => 'task_reminders'],
            [
                'name' => $feature['name'],
                'description' => $feature['description'],
                'module' => $feature['module'],
                'icon' => $feature['icon'],
                'is_enabled' => true,
                'sort_order' => $maxSortOrder + 1,
                'route_prefixes' => json_encode($feature['route_prefixes']),
                'depends_on' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // The feature map is cached forever (see FeatureManager) — a new row
        // added outside the toggle-controller flow must flush it itself, or
        // non-Super-Admins get silently 403'd until something else clears it.
        FeatureManager::flushCache();
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'task_reminders')->delete();

        FeatureManager::flushCache();
    }
};
