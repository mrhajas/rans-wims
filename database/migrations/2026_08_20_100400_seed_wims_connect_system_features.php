<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the four wims_connect* rows added to SystemFeatureRegistry, plus
     * a placeholder wims_connect_gateways row per driver so the Overview
     * page has something to read on first load. Everything starts disabled
     * — no gateway has real credentials yet, and the module itself
     * shouldn't appear "on" until a Super Admin deliberately switches it on
     * (see the master-switch requirement: OFF means nothing external ever
     * sends, regardless of individual gateway state).
     */
    public function up(): void
    {
        $keys = ['wims_connect', 'wims_connect_whatsapp', 'wims_connect_meta', 'wims_connect_sms'];
        $features = SystemFeatureRegistry::features();
        $sortOrder = 5;

        foreach ($keys as $key) {
            $feature = $features[$key];

            DB::table('system_features')->updateOrInsert(
                ['key' => $key],
                [
                    'name' => $feature['name'],
                    'description' => $feature['description'],
                    'module' => $feature['module'],
                    'icon' => $feature['icon'],
                    'is_enabled' => false,
                    'sort_order' => $sortOrder,
                    'route_prefixes' => json_encode($feature['route_prefixes']),
                    'depends_on' => $feature['depends_on'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $sortOrder += 10;
        }

        foreach (['whatsapp', 'meta', 'sms'] as $driver) {
            DB::table('wims_connect_gateways')->updateOrInsert(
                ['driver' => $driver],
                [
                    'is_enabled' => false,
                    'status' => 'disconnected',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('system_features')->whereIn('key', [
            'wims_connect', 'wims_connect_whatsapp', 'wims_connect_meta', 'wims_connect_sms',
        ])->delete();

        DB::table('wims_connect_gateways')->whereIn('driver', ['whatsapp', 'meta', 'sms'])->delete();
    }
};
