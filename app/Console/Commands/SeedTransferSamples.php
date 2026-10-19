<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\GatePass;
use App\Models\Item;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\ReferenceLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fourteen sample Transfers (SMP-TX-0001 ...) in every state a transfer can be in, with Gate Passes and Delivery Notes for the ones that
 * have left or arrived, so the list, the filters and the printed paperwork can be tried. Nothing is stocked or moved and nobody is told.
 * Everything is marked SAMPLE so `--remove` deletes exactly the samples (and their paperwork).
 */
class SeedTransferSamples extends Command
{
    protected $signature = 'wims:seed-transfer-samples {--remove : Delete the sample transfers (and their Gate Passes / Delivery Notes) instead}';

    protected $description = 'Add (or remove) 14 sample Transfers in different states, with Gate Passes and Delivery Notes';

    private const MARK = 'SAMPLE';

    public function handle(): int
    {
        if ($this->option('remove')) {
            return $this->remove();
        }

        $by = User::role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tempdebug%')->orderBy('id')->first();
        $main = Warehouse::withoutGlobalScopes()->mainWarehouses()->whereNull('deleted_at')->orderBy('id')->get();
        $projects = Warehouse::withoutGlobalScopes()->whereNull('deleted_at')->where('status', '!=', 'Main Warehouse')->orderBy('id')->get();
        $items = Item::query()->orderBy('id')->limit(40)->get();
        if (! $by || $projects->count() < 2 || $items->count() < 3) {
            $this->error('Need a Super Admin, at least two projects and three items.');

            return self::FAILURE;
        }
        $drivers = Contact::query()->where('category', 'Drivers')->orderBy('id')->limit(6)->get();
        $vehicles = Asset::query()->where('asset_type', 'Vehicle')->orderBy('id')->limit(6)->get();

        // [days ago, state, from main?, number of items, notes]
        $plan = [
            [29, 'received', true, 3, 'cement and steel for the foundation'],
            [26, 'received', false, 2, 'tools moved between two sites'],
            [23, 'received', true, 4, 'finishing materials'],
            [20, 'approved', true, 2, 'approved, waiting to be dispatched'],
            [17, 'pending', true, 3, 'waiting for approval'],
            [14, 'received', false, 3, 'scaffolding returned'],
            [12, 'cancelled', true, 2, 'cancelled, the site was closed'],
            [10, 'draft', true, 2, 'draft, not finished yet'],
            [8, 'approved', true, 3, 'plumbing and electrical items'],
            [6, 'pending', true, 2, 'waiting for approval'],
            [5, 'received', true, 4, 'blocks and sand'],
            [3, 'approved', false, 2, 'equipment to the new site'],
            [2, 'received', false, 3, 'paint and brushes'],
            [1, 'pending', true, 2, 'urgent request'],
        ];

        $made = ['transfers' => 0, 'passes' => 0, 'notes' => 0];
        foreach ($plan as $i => [$days, $state, $fromMain, $n, $note]) {
            $ref = 'SMP-TX-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
            if (Transfer::withoutGlobalScopes()->withTrashed()->where('reference', $ref)->exists()) {
                continue;
            }
            $from = ($fromMain && $main->isNotEmpty()) ? $main[$i % $main->count()] : $projects[$i % $projects->count()];
            $to = $projects[($i + 3) % $projects->count()];
            if ($to->id === $from->id) {
                $to = $projects[($i + 4) % $projects->count()];
            }
            $date = now()->subDays($days);
            $needsApproval = $fromMain && $main->isNotEmpty();
            $approval = match (true) {
                $state === 'pending' => Transfer::APPROVAL_PENDING,
                $state === 'cancelled' => Transfer::APPROVAL_CANCELLED,
                $state === 'draft' => $needsApproval ? Transfer::APPROVAL_PENDING : Transfer::APPROVAL_NOT_REQUIRED,
                $needsApproval => Transfer::APPROVAL_APPROVED,
                default => Transfer::APPROVAL_NOT_REQUIRED,
            };
            $t = Transfer::create([
                'date' => $date->format('Y-m-d'), 'reference' => $ref, 'draft' => $state === 'draft',
                'approval_status' => $approval, 'approved_by' => in_array($approval, [Transfer::APPROVAL_APPROVED], true) ? $by->id : null,
                'approved_at' => $approval === Transfer::APPROVAL_APPROVED ? $date : null,
                'cancelled_by' => $state === 'cancelled' ? $by->id : null, 'cancelled_at' => $state === 'cancelled' ? $date : null, 'cancel_reason' => $state === 'cancelled' ? 'The site was closed' : null,
                'received_at' => $state === 'received' ? $date->copy()->addDays(1) : null, 'received_by' => $state === 'received' ? $by->id : null,
                'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'user_id' => $by->id, 'account_id' => $by->account_id,
                'details' => self::MARK . ': ' . $note,
            ]);
            foreach ($items->slice(($i * 3) % max(1, $items->count() - 6), $n) as $k => $item) {
                TransferItem::create(['transfer_id' => $t->id, 'item_id' => $item->id, 'quantity' => [10, 25, 50, 100, 5, 40][($i + $k) % 6], 'unit_id' => $item->unit_id ?? null, 'account_id' => $by->account_id, 'draft' => $state === 'draft', 'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id]);
            }
            $made['transfers']++;

            // paperwork for the transfers that have left or arrived
            if (in_array($state, ['received', 'approved'], true)) {
                $driver = $drivers->isNotEmpty() ? $drivers[$i % $drivers->count()] : null;
                $vehicle = $vehicles->isNotEmpty() ? $vehicles[$i % $vehicles->count()] : null;
                ReferenceLock::run('gate_passes', function () use ($t, $by, $driver, $vehicle, $i) {
                    GatePass::create([
                        'account_id' => $by->account_id, 'reference' => GatePass::nextReference(), 'source_type' => GatePass::SOURCE_TRANSFER, 'source_id' => $t->id,
                        'driver_contact_id' => $driver?->id, 'driver_name' => $driver ? null : 'Sample Driver ' . ($i + 1), 'driver_phone' => $driver ? null : '+25570000' . str_pad((string) (200 + $i), 4, '0', STR_PAD_LEFT),
                        'asset_id' => $vehicle?->id, 'vehicle_plate' => $vehicle ? null : 'Z ' . (100 + $i) . ' SMP', 'vehicle_details' => $vehicle ? null : 'Sample truck',
                        'created_by_user_id' => $by->id,
                    ]);
                });
                $made['passes']++;
            }
            if ($state === 'received') {
                ReferenceLock::run('delivery_notes', function () use ($t, $by) {
                    DeliveryNote::create(['account_id' => $by->account_id, 'reference' => DeliveryNote::nextReference(), 'source_type' => DeliveryNote::SOURCE_TRANSFER, 'source_id' => $t->id, 'created_by_user_id' => $by->id]);
                });
                $made['notes']++;
            }
        }

        $this->info("Added {$made['transfers']} sample Transfer(s), {$made['passes']} Gate Pass(es) and {$made['notes']} Delivery Note(s). Remove them with: php artisan wims:seed-transfer-samples --remove");

        return self::SUCCESS;
    }

    private function remove(): int
    {
        $ids = Transfer::withoutGlobalScopes()->withTrashed()->where('reference', 'like', 'SMP-TX-%')->where('details', 'like', self::MARK . '%')->pluck('id');
        DB::transaction(function () use ($ids) {
            GatePass::withTrashed()->where('source_type', GatePass::SOURCE_TRANSFER)->whereIn('source_id', $ids)->forceDelete();
            DeliveryNote::withTrashed()->where('source_type', DeliveryNote::SOURCE_TRANSFER)->whereIn('source_id', $ids)->forceDelete();
            TransferItem::withoutGlobalScopes()->withTrashed()->whereIn('transfer_id', $ids)->forceDelete();
            Transfer::withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->forceDelete();
        });
        $this->info('Removed ' . $ids->count() . ' sample Transfer(s) with their Gate Passes and Delivery Notes.');

        return self::SUCCESS;
    }
}
