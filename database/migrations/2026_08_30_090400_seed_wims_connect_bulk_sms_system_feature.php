<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hardcoded rather than read from SystemFeatureRegistry::features() — a
     * migration is a historical snapshot and must not depend on the
     * present-day registry, which later renamed this exact module key to
     * 'wims_connect_bulk_connect' (see
     * 2026_08_30_100100_rename_bulk_sms_to_bulk_connect_system_feature.php,
     * which immediately overwrites name/description/route_prefixes/
     * depends_on anyway — this row's job is just to exist for that rename
     * to find).
     */
    public function up(): void
    {
        $maxSortOrder = (int) DB::table('system_features')->max('sort_order');

        DB::table('system_features')->updateOrInsert(
            ['key' => 'wims_connect_bulk_sms'],
            [
                'name' => 'WIMS Connect — Bulk SMS',
                'description' => 'Ad-hoc bulk SMS composition, scheduling, and delivery history.',
                'module' => 'WIMS Connect',
                'icon' => 'send',
                'is_enabled' => true,
                'sort_order' => $maxSortOrder + 1,
                'route_prefixes' => json_encode(['wims-connect.bulk-sms.']),
                'depends_on' => 'wims_connect_sms',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'wims_connect_bulk_sms')->delete();
    }
};
