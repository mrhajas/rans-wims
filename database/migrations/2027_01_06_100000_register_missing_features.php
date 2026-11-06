<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global Feature Control: every function that exists in the code registry must have a switch. Functions added to the code after the table
 * was last filled (Stock Archive, Performance & Data, Simulation WIMS ...) had none, so nothing could switch them off. Adds the missing
 * rows (switched ON, like the "Add the missing functions" button of the Registration check); never changes or removes an existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_features')) {
            return;
        }

        $have = DB::table('system_features')->pluck('key')->all();
        $order = (int) DB::table('system_features')->max('sort_order');

        foreach (SystemFeatureRegistry::features() as $key => $f) {
            if (in_array($key, $have, true)) {
                continue;
            }
            DB::table('system_features')->insert([
                'key' => $key, 'name' => $f['name'], 'description' => $f['description'] ?? null, 'module' => $f['module'] ?? null, 'icon' => $f['icon'] ?? null,
                'is_enabled' => 1, 'depends_on' => $f['depends_on'] ?? null, 'route_prefixes' => json_encode($f['route_prefixes'] ?? []),
                'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        FeatureManager::flushCache();
    }

    public function down(): void
    {
        // the rows stay: removing a switch would silently switch the function back on
    }
};
