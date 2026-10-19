<?php

namespace App\Services;

use App\Models\Asset;

/**
 * Straight-line depreciation only (spec-scoped for v1). Book value is never
 * allowed to fall below the asset's configured residual value.
 */
class DepreciationCalculator
{
    public function postPeriod(Asset $asset, string $periodStart, string $periodEnd, int $userId): \App\Models\AssetDepreciationEntry
    {
        $latest = $asset->depreciationEntries()->first();
        $opening = $latest ? (float) $latest->closing_book_value : (float) $asset->total_capitalised_cost;

        $periodDepreciation = $this->periodDepreciation($asset, $opening);
        $closing = max($opening - $periodDepreciation, (float) $asset->residual_value);
        // Clamp the charge itself so it never drives the book value past the floor.
        $periodDepreciation = $opening - $closing;

        $accumulated = (float) $asset->total_capitalised_cost - $closing;

        return $asset->depreciationEntries()->create([
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'opening_book_value' => $opening,
            'period_depreciation' => $periodDepreciation,
            'accumulated_depreciation' => $accumulated,
            'closing_book_value' => $closing,
            'posted_by_user_id' => $userId,
            'posted_at' => now(),
        ]);
    }

    private function periodDepreciation(Asset $asset, float $opening): float
    {
        if ($opening <= (float) $asset->residual_value || ! $asset->useful_life_years) {
            return 0;
        }

        $periodsPerYear = match ($asset->depreciation_frequency) {
            'Monthly' => 12,
            'Quarterly' => 4,
            default => 1,
        };

        $annualDepreciation = ((float) $asset->total_capitalised_cost - (float) $asset->residual_value) / $asset->useful_life_years;

        return $annualDepreciation / $periodsPerYear;
    }
}
