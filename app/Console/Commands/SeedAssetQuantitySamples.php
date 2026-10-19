<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AssetPlacements;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo assets with quantities, for trying Check-Out / Check-In / Transfer of some of the pieces: three clearly marked sample
 * sites and three sample assets (40 scaffolding frames, 100 safety helmets, 250 metres of steel cable) all starting at Sample
 * Site A. Nothing outside the sample sites and sample assets is touched, and nobody is notified.
 *
 *   php artisan wims:seed-asset-quantity-samples           add the samples (does nothing if they already exist)
 *   php artisan wims:seed-asset-quantity-samples --remove  delete every sample record again
 */
class SeedAssetQuantitySamples extends Command
{
    protected $signature = 'wims:seed-asset-quantity-samples {--remove : Delete the sample records instead of creating them}';

    protected $description = 'Create (or remove) sample assets with quantities on dedicated sample sites';

    private const SITES = ['SAMPLE-AQ-A' => 'SAMPLE Site A (Asset quantities)', 'SAMPLE-AQ-B' => 'SAMPLE Site B (Asset quantities)', 'SAMPLE-AQ-C' => 'SAMPLE Site C (Asset quantities)'];

    /** [asset name, quantity, unit code, acquisition cost per item, transport per item] */
    private const ASSETS = [
        ['SAMPLE Scaffolding Frames', 40, 'pcs', 35000, 2000],
        ['SAMPLE Safety Helmets', 100, 'pcs', 12000, 0],
        ['SAMPLE Steel Cable', 250, 'mtr', 4500, 150],
    ];

    public function handle(): int
    {
        $admin = User::withoutGlobalScopes()->role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tempdebug%')->orderBy('id')->first();
        if (! $admin) {
            $this->error('No Super Admin user found.');

            return self::FAILURE;
        }
        Auth::setUser($admin);

        return $this->option('remove') ? $this->remove() : $this->create($admin);
    }

    private function create(User $admin): int
    {
        if (Warehouse::withoutGlobalScopes()->whereIn('code', array_keys(self::SITES))->exists()) {
            $this->warn('Sample records already exist. Run with --remove first to recreate them.');

            return self::SUCCESS;
        }
        $accountId = $admin->account_id;
        DB::transaction(function () use ($admin, $accountId) {
            $sites = [];
            foreach (self::SITES as $code => $name) {
                $sites[] = Warehouse::create(['name' => $name, 'code' => $code, 'status' => 'General', 'active' => true, 'account_id' => $accountId]);
            }
            foreach (self::ASSETS as [$name, $qty, $unitCode, $cost, $transport]) {
                $unit = Unit::withoutGlobalScopes()->where('code', $unitCode)->first() ?: Unit::withoutGlobalScopes()->first();
                $maxSuffix = (int) DB::table('assets')->selectRaw('MAX(CAST(SUBSTRING(asset_number, 5) AS UNSIGNED)) as m')->where('asset_number', 'like', 'AST-%')->value('m');
                $asset = Asset::create([
                    'asset_number' => 'AST-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT),
                    'name' => $name, 'asset_type' => 'UnFixed Asset', 'status' => 'Available', 'condition' => 'New', 'ownership_type' => 'Company Owned',
                    'quantity' => $qty, 'unit_id' => $unit->id, 'currency' => 'TZS',
                    'acquisition_cost' => $cost, 'transport_cost' => $transport, 'installation_cost' => 0, 'other_capitalised_cost' => 0,
                    'total_capitalised_cost' => ($cost + $transport) * $qty,
                    'acquisition_date' => now()->subDays(30)->toDateString(),
                    'current_warehouse_id' => $sites[0]->id, 'registered_in' => Asset::REGISTERED_IN_ASSETS, 'created_by_user_id' => $admin->id,
                    'description' => 'Sample asset with a quantity, for trying partial Check-Out / Check-In / Transfer.',
                ]);
                AssetPlacements::ensure($asset);
                AssetMovement::create([
                    'asset_id' => $asset->id, 'movement_type' => 'Registered', 'quantity' => $qty, 'unit_id' => $unit->id,
                    'to_warehouse_id' => $sites[0]->id, 'movement_date' => now()->subDays(30)->toDateString(), 'performed_by_user_id' => $admin->id, 'notes' => 'Asset registered.',
                ]);
            }
        });
        $this->info('3 sample sites and 3 sample assets created (all pieces start at SAMPLE Site A).');

        return self::SUCCESS;
    }

    private function remove(): int
    {
        DB::transaction(function () {
            $ids = Asset::withoutGlobalScopes()->withTrashed()->whereIn('name', array_column(self::ASSETS, 0))->where('description', 'like', 'Sample asset with a quantity%')->pluck('id');
            DB::table('asset_movements')->whereIn('asset_id', $ids)->delete();
            DB::table('asset_placements')->whereIn('asset_id', $ids)->delete();
            Asset::withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->forceDelete();
            $sites = Warehouse::withoutGlobalScopes()->withTrashed()->whereIn('code', array_keys(self::SITES))->pluck('id');
            DB::table('user_warehouse')->whereIn('warehouse_id', $sites)->delete();
            Warehouse::withoutGlobalScopes()->withTrashed()->whereIn('id', $sites)->forceDelete();
        });
        $this->info('Sample sites and sample assets removed.');

        return self::SUCCESS;
    }
}
