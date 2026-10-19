<?php

namespace App\Console\Commands;

use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use App\Models\Item;
use App\Models\Stock;
use App\Models\SuperAdjustment;
use App\Models\SuperAdjustmentItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\ApproverRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo records for the Adjustment and Super Adjustment pages: one clearly marked sample Project / Warehouse with a few
 * sample items, then Pending, Approved and Rejected Adjustments and Super Adjustments on top of it. Nothing outside that
 * sample warehouse is touched, and records are written directly (no events), so nobody is emailed or notified.
 *
 *   php artisan wims:seed-adjustment-samples           add the samples (does nothing if they already exist)
 *   php artisan wims:seed-adjustment-samples --remove  delete every sample record again
 */
class SeedAdjustmentSamples extends Command
{
    protected $signature = 'wims:seed-adjustment-samples {--remove : Delete the sample records instead of creating them}';

    protected $description = 'Create (or remove) sample Adjustments and Super Adjustments on a dedicated sample warehouse';

    private const WAREHOUSE_CODE = 'SAMPLE-ADJ';

    private const ITEMS = [
        ['SMP-CEM', 'Sample Cement 50kg', 'pcs', 120],
        ['SMP-STL', 'Sample Steel Bar 12mm', 'pcs', 80],
        ['SMP-PNT', 'Sample Paint 20L', 'pcs', 40],
        ['SMP-GLV', 'Sample Safety Gloves', 'pcs', 60],
        ['SMP-SND', 'Sample Sand (tonne)', 'pcs', 90],
    ];

    public function handle(): int
    {
        $admin = User::withoutGlobalScopes()->role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tempdebug%')->orderBy('id')->first();
        if (! $admin) {
            $this->error('No Super Admin user found.');

            return self::FAILURE;
        }
        Auth::setUser($admin);

        return $this->option('remove') ? $this->remove($admin) : $this->create($admin);
    }

    private function create(User $admin): int
    {
        $accountId = $admin->account_id;
        if (Warehouse::withoutGlobalScopes()->where('code', self::WAREHOUSE_CODE)->exists()) {
            $this->warn('Sample records already exist. Run with --remove first to recreate them.');

            return self::SUCCESS;
        }

        // Real, active people only (the usual user scopes already hide deleted ones); never throw-away test accounts.
        $people = fn (string $role) => User::role($role)->where('is_active', true)->where('username', 'not like', 'tempdebug%')->orderBy('id')->first();
        $maker = $people('Procurement Manager') ?: $people('Engineers') ?: $admin;
        $otherAdmin = $people('Admin');
        $unit = Unit::withoutGlobalScopes()->where('code', 'pcs')->first() ?: Unit::withoutGlobalScopes()->first();

        DB::transaction(function () use ($admin, $maker, $otherAdmin, $unit, $accountId) {
            $warehouse = Warehouse::create(['name' => 'SAMPLE PROJECT (Adjustment demo)', 'code' => self::WAREHOUSE_CODE, 'status' => 'General', 'active' => true, 'account_id' => $accountId]);

            $items = [];
            foreach (self::ITEMS as [$code, $name, , $qty]) {
                $item = Item::create(['name' => $name, 'code' => $code, 'track_quantity' => 1, 'unit_id' => $unit->id, 'account_id' => $accountId]);
                Stock::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => $qty, 'account_id' => $accountId]);
                $items[$code] = $item;
            }

            // [type, maker, lines [code => qty], state, days ago, approver (Super Admin unless stated), rejection reason]
            $adjustments = [
                ['Addition', $maker, ['SMP-CEM' => 20, 'SMP-STL' => 10], 'Pending', 1, null, null],
                ['Damage', $maker, ['SMP-PNT' => 3], 'Pending', 2, null, null],
                ['Subtraction', $maker, ['SMP-GLV' => 5, 'SMP-SND' => 12], 'Approved', 4, $admin, null],
                ['Addition', $maker, ['SMP-STL' => 15], 'Approved', 6, $otherAdmin ?: $admin, null],
                ['Subtraction', $maker, ['SMP-SND' => 10], 'Rejected', 8, $admin, 'The quantity does not match the physical count sheet.'],
                ['Damage', $maker, ['SMP-CEM' => 8], 'Rejected', 9, $admin, 'Photos of the damage were not attached. Please attach them and submit again.'],
            ];
            foreach ($adjustments as [$type, $by, $lines, $state, $daysAgo, $approver, $reason]) {
                $this->makeAdjustment($warehouse, $items, $unit, $type, $by, $lines, $state, $daysAgo, $approver, $reason);
            }

            // Super Adjustments: a Draft waiting for a Super Admin, one approved by a Super Admin, one rejected.
            $this->makeSuperAdjustment($warehouse, $items, $unit, $maker, ['SMP-CEM' => 118, 'SMP-GLV' => 55, 'SMP-PNT' => 40], 'Draft', 1, null, null, 'Monthly physical count');
            $this->makeSuperAdjustment($warehouse, $items, $unit, $admin, ['SMP-STL' => 100, 'SMP-SND' => 70], 'Approved', 5, $admin, null, 'Year-end stock take', true);
            $this->makeSuperAdjustment($warehouse, $items, $unit, $maker, ['SMP-PNT' => 10, 'SMP-GLV' => 0], 'Rejected', 7, $admin, 'Count sheet was not signed by the store keeper.', 'Spot check');
        });

        $this->info('Sample warehouse, 5 sample items, 6 Adjustments and 3 Super Adjustments created.');

        return self::SUCCESS;
    }

    private function makeAdjustment(Warehouse $warehouse, array $items, Unit $unit, string $type, User $by, array $lines, string $state, int $daysAgo, ?User $approver, ?string $reason): void
    {
        $when = now()->subDays($daysAgo);
        $adjustment = Adjustment::create([
            'reference' => $this->nextReference('adjustments', 'RSA-', 5),
            'date' => $when->toDateString(),
            'type' => $type,
            'warehouse_id' => $warehouse->id,
            'user_id' => $by->id,
            'account_id' => $warehouse->account_id,
            'draft' => $state !== 'Approved',
            'details' => 'Sample record - ' . strtolower($state) . ' ' . strtolower($type) . ' adjustment.',
        ]);
        $this->stamp($adjustment, $when);

        foreach ($lines as $code => $qty) {
            AdjustmentItem::create([
                'adjustment_id' => $adjustment->id, 'item_id' => $items[$code]->id, 'quantity' => $qty, 'unit_id' => $unit->id,
                'draft' => $state !== 'Approved', 'warehouse_id' => $warehouse->id, 'type' => $type, 'account_id' => $warehouse->account_id,
            ]);
            if ($state === 'Approved') {
                $this->moveStock($warehouse, $items[$code], $type === 'Addition' ? $qty : -$qty);
            }
        }

        if ($state === 'Approved') {
            $adjustment->forceFill(['approved_by' => $approver->id, 'approved_by_role' => ApproverRole::for($approver), 'approved_at' => $when->copy()->addHours(3)])->saveQuietly();
        } elseif ($state === 'Rejected') {
            $adjustment->forceFill(['rejected_by' => $approver->id, 'rejected_at' => $when->copy()->addHours(5), 'rejection_reason' => $reason])->saveQuietly();
        }
    }

    private function makeSuperAdjustment(Warehouse $warehouse, array $items, Unit $unit, User $by, array $counts, string $state, int $daysAgo, ?User $approver, ?string $reason, string $why, bool $auto = false): void
    {
        $when = now()->subDays($daysAgo);
        $sa = SuperAdjustment::create([
            'reference' => $this->nextReference('super_adjustments', 'SADJ-', 6),
            'warehouse_id' => $warehouse->id, 'date' => $when->toDateString(), 'user_id' => $by->id, 'account_id' => $warehouse->account_id,
            'reason' => $why, 'details' => 'Sample record - ' . strtolower($state) . '.', 'status' => SuperAdjustment::STATUS_DRAFT,
        ]);
        $this->stamp($sa, $when);

        foreach ($counts as $code => $physical) {
            $system = (float) Stock::where('warehouse_id', $warehouse->id)->where('item_id', $items[$code]->id)->value('quantity');
            SuperAdjustmentItem::create([
                'super_adjustment_id' => $sa->id, 'item_id' => $items[$code]->id, 'unit_id' => $unit->id,
                'system_quantity_at_submission' => $system, 'physical_quantity' => $physical, 'auto_cleared' => $physical == 0 && $system > 0, 'account_id' => $warehouse->account_id,
            ]);
        }

        if ($state === 'Approved') {
            // What approval does: the counted quantity becomes the stock (the variance is applied as Addition / Subtraction).
            foreach ($counts as $code => $physical) {
                $this->moveStock($warehouse, $items[$code], $physical - (float) Stock::where('warehouse_id', $warehouse->id)->where('item_id', $items[$code]->id)->value('quantity'));
            }
            $sa->forceFill(['status' => SuperAdjustment::STATUS_APPROVED, 'approved_by_user_id' => $approver->id, 'approved_by_role' => ApproverRole::for($approver), 'approved_at' => $when->copy()->addMinutes($auto ? 1 : 180)])->saveQuietly();
        } elseif ($state === 'Rejected') {
            $sa->forceFill(['status' => SuperAdjustment::STATUS_REJECTED, 'rejected_by_user_id' => $approver->id, 'rejected_at' => $when->copy()->addHours(4), 'rejection_reason' => $reason])->saveQuietly();
        }
    }

    private function moveStock(Warehouse $warehouse, Item $item, float $delta): void
    {
        if ($delta != 0) {
            Stock::where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->increment('quantity', $delta);
        }
    }

    private function stamp($model, $when): void
    {
        $model->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
    }

    private function nextReference(string $table, string $prefix, int $offset): string
    {
        $max = (int) DB::table($table)->selectRaw("MAX(CAST(SUBSTRING(reference, $offset) AS UNSIGNED)) as m")->where('reference', 'like', $prefix . '%')->value('m');

        return $prefix . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    }

    private function remove(User $admin): int
    {
        $warehouse = Warehouse::withoutGlobalScopes()->withTrashed()->where('code', self::WAREHOUSE_CODE)->first();
        if (! $warehouse) {
            $this->info('No sample records found.');

            return self::SUCCESS;
        }

        $itemIds = Item::withoutGlobalScopes()->withTrashed()->where('code', 'like', 'SMP-%')->pluck('id');
        DB::transaction(function () use ($warehouse, $itemIds) {
            $adjustmentIds = DB::table('adjustments')->where('warehouse_id', $warehouse->id)->pluck('id');
            $superIds = DB::table('super_adjustments')->where('warehouse_id', $warehouse->id)->pluck('id');

            DB::table('adjustment_items')->whereIn('adjustment_id', $adjustmentIds)->delete();
            DB::table('adjustments')->whereIn('id', $adjustmentIds)->delete();
            DB::table('super_adjustment_items')->whereIn('super_adjustment_id', $superIds)->delete();
            DB::table('super_adjustments')->whereIn('id', $superIds)->delete();
            DB::table('stock_trails')->whereIn('item_id', $itemIds)->delete();
            DB::table('stocks')->where('warehouse_id', $warehouse->id)->delete();
            DB::table('items')->whereIn('id', $itemIds)->delete();
            DB::table('warehouses')->where('id', $warehouse->id)->delete();

            DB::table('activity_log')->where(function ($q) use ($adjustmentIds, $superIds, $itemIds, $warehouse) {
                $q->where(fn ($w) => $w->where('subject_type', Adjustment::class)->whereIn('subject_id', $adjustmentIds))
                    ->orWhere(fn ($w) => $w->where('subject_type', SuperAdjustment::class)->whereIn('subject_id', $superIds))
                    ->orWhere(fn ($w) => $w->where('subject_type', Item::class)->whereIn('subject_id', $itemIds))
                    ->orWhere(fn ($w) => $w->where('subject_type', Warehouse::class)->where('subject_id', $warehouse->id));
            })->delete();
        });

        $this->info('Sample records removed.');

        return self::SUCCESS;
    }
}
