<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors 2026_08_16_032827_rename_ai_assistant_feature_to_aris.php — the
 * 'transport_management' feature key, its route_prefixes, and every
 * permission ability string stay exactly as-is (renaming any of those would
 * break the FeatureGate middleware's route matching and any already-granted
 * role permissions); only the human-facing display name changes, and only
 * in the system_features table this row was already seeded into — the
 * SystemFeatureRegistry class itself was updated directly, but
 * SystemFeatureController::index() reads the persisted DB row, not the
 * registry, on every request, so that source-only change alone would never
 * reach the Feature Control screen for a feature already seeded before this
 * migration.
 */
return new class extends Migration
{
    protected string $previousName = 'Transport Management';

    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['transport_management'];

        DB::table('system_features')->where('key', 'transport_management')->update([
            'name' => $feature['name'],
            'updated_at' => now(),
        ]);

        FeatureManager::flushCache();
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'transport_management')->update([
            'name' => $this->previousName,
            'updated_at' => now(),
        ]);

        FeatureManager::flushCache();
    }
};
