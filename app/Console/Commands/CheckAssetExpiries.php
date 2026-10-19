<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\AssetNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckAssetExpiries extends Command
{
    protected $signature = 'assets:check-expiries';

    protected $description = 'Notify about assets whose warranty, insurance, registration, inspection, or license is approaching expiry.';

    // Fixed thresholds — simpler than Compliance's configurable reminder
    // schedule, matching the "display-only, basic expiry flag" depth
    // agreed for Asset's warranty/insurance fields.
    private const THRESHOLDS = [30, 14, 7, 1];

    private const FIELDS = [
        'warranty_end' => 'Warranty',
        'insurance_expiry' => 'Insurance',
        'registration_expiry' => 'Registration',
        'inspection_expiry' => 'Inspection',
        'license_expiry' => 'License',
    ];

    public function handle(): int
    {
        $today = now()->startOfDay();
        $notified = 0;

        $assets = Asset::query()->active()->get();

        foreach ($assets as $asset) {
            foreach (self::FIELDS as $field => $label) {
                $date = $asset->{$field};
                if (! $date) {
                    continue;
                }

                try {
                    $daysRemaining = (int) $today->diffInDays($date->copy()->startOfDay(), false);

                    if (! in_array($daysRemaining, self::THRESHOLDS, true)) {
                        continue;
                    }

                    // one notice per asset, date field and day count (older notices carry asset_id / field / days_remaining, newer ones the reference)
                    $reference = "asset-expiry:{$asset->id}:{$field}:{$daysRemaining}";
                    $already = DB::table('notifications')
                        ->where(fn ($q) => $q->where('data->reference', $reference)
                            ->orWhere(fn ($q2) => $q2->where('data->type', 'asset_expiry')->where('data->asset_id', $asset->id)->where('data->field', $field)->where('data->days_remaining', $daysRemaining)))
                        ->exists();
                    if ($already) {
                        continue;
                    }

                    // the Assets workflow page decides who is told (the assigned person and the finance viewers to start with)
                    AssetNotifier::fire(
                        'expiry_reminder',
                        $asset,
                        __(':label expiring - :asset', ['label' => $label, 'asset' => $asset->name]),
                        __(':label for :asset (:number) expires in :days day(s).', ['label' => $label, 'asset' => $asset->name, 'number' => $asset->asset_number, 'days' => $daysRemaining]),
                        ['reference' => $reference, 'expiring' => $label, 'days' => (string) $daysRemaining, 'expirydate' => $date->format('d M Y')],
                        ['dedupe' => $reference],
                    );
                    $notified++;
                } catch (\Throwable $e) {
                    Log::error('Failed to check asset expiry', [
                        'asset_id' => $asset->id,
                        'field' => $field,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Sent {$notified} asset expiry notification(s).");

        return 0;
    }
}
