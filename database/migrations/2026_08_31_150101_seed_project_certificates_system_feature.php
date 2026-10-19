<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hardcoded rather than read from SystemFeatureRegistry::features() — see
 * 2026_08_30_090400_seed_wims_connect_bulk_sms_system_feature.php's docblock
 * for why a migration must not depend on the present-day registry.
 */
return new class extends Migration
{
    public function up(): void
    {
        $maxSortOrder = (int) DB::table('system_features')->max('sort_order');

        DB::table('system_features')->updateOrInsert(
            ['key' => 'project_certificates'],
            [
                'name' => 'Project Certificates',
                'description' => 'Contractors\' Statements of Account / interim payment certificates, per-project calculation and payment tracking.',
                'module' => 'Project Certificates',
                'icon' => 'doc-text',
                'is_enabled' => true,
                'sort_order' => $maxSortOrder + 1,
                'route_prefixes' => json_encode(['project-certificates.']),
                'depends_on' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'project_certificates')->delete();
    }
};
