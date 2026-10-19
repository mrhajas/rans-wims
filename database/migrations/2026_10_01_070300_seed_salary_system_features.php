<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Seeds just the new salary_* keys — same idempotent shape as the original seed_system_features migration, safe to run alongside existing rows. */
    public function up(): void
    {
        $keys = ['salary', 'salary_preparation', 'salary_payments', 'salary_vouchers', 'salary_advances', 'salary_loans', 'salary_analytics', 'salary_reports'];
        $features = SystemFeatureRegistry::features();
        $sortOrder = (int) DB::table('system_features')->max('sort_order') + 1;

        foreach ($keys as $key) {
            $feature = $features[$key];

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
        DB::table('system_features')->whereIn('key', [
            'salary', 'salary_preparation', 'salary_payments', 'salary_vouchers',
            'salary_advances', 'salary_loans', 'salary_analytics', 'salary_reports',
        ])->delete();
    }
};
