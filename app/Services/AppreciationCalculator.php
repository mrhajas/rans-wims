<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAppreciationEntry;

/**
 * Appreciation is valuation-based, not formula-driven (unlike straight-line
 * depreciation) — the user supplies the newly appraised value for the
 * period, and the entry just records the delta against the last known
 * value (or the original capitalised cost, for the first entry).
 */
class AppreciationCalculator
{
    public function postPeriod(Asset $asset, string $periodStart, string $periodEnd, float $newValue, int $userId): AssetAppreciationEntry
    {
        $latest = $asset->appreciationEntries()->first();
        $opening = $latest ? (float) $latest->closing_value : (float) $asset->total_capitalised_cost;

        $periodAppreciation = $newValue - $opening;
        $accumulated = $newValue - (float) $asset->total_capitalised_cost;

        return $asset->appreciationEntries()->create([
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'opening_value' => $opening,
            'period_appreciation' => $periodAppreciation,
            'accumulated_appreciation' => $accumulated,
            'closing_value' => $newValue,
            'posted_by_user_id' => $userId,
            'posted_at' => now(),
        ]);
    }
}
