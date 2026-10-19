<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026_10_01_070300_seed_salary_system_features.php seeded these rows from
 * SystemFeatureRegistry at migration-run time; the registry was edited
 * afterward (salary_analytics gained the 'salary.analytics' route prefix,
 * salary_payments was renamed/re-described to drop the already-covered
 * "Approval" wording) with no follow-up migration, so the live DB rows
 * drifted from the code — disabling "Salary — Dashboard & Analytics" in
 * Feature Control silently failed to block the salary.analytics route.
 * This re-syncs just those two rows' name/description/route_prefixes,
 * preserving whatever is_enabled an admin may have already set.
 */
return new class extends Migration
{
    public function up(): void
    {
        $features = SystemFeatureRegistry::features();

        foreach (['salary_analytics', 'salary_payments'] as $key) {
            $feature = $features[$key] ?? null;

            if (! $feature) {
                continue;
            }

            DB::table('system_features')->where('key', $key)->update([
                'name' => $feature['name'],
                'description' => $feature['description'],
                'route_prefixes' => json_encode($feature['route_prefixes']),
                'updated_at' => now(),
            ]);
        }

        FeatureManager::flushCache();
    }

    public function down(): void
    {
        FeatureManager::flushCache();
    }
};
