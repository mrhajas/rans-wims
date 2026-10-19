<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Contact;
use App\Models\GatePass;
use App\Models\Transfer;
use App\Models\TransportLocation;
use App\Models\TransportRecord;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Transport\TransferRoute;
use App\Services\TransportNotifier;
use App\Support\ReferenceLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ten sample Transport Routes trips: FIVE generated straight from Transfers (the Gate Pass supplies the driver and vehicle, the Transfer the
 * from / to) and FIVE logged on their own. Everything is marked SAMPLE (trip notes, and the Gate Passes made for the occasion) so `--remove`
 * deletes exactly the samples. No notification is sent while seeding.
 */
class SeedTransportSamples extends Command
{
    protected $signature = 'wims:seed-transport-samples {--remove : Delete the sample trips (and the sample Gate Passes) instead}';

    protected $description = 'Add (or remove) 10 sample Transport Routes trips, 5 of them generated from Transfers';

    private const MARK = 'SAMPLE';

    public function handle(): int
    {
        if ($this->option('remove')) {
            return $this->remove();
        }

        $by = User::role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tempdebug%')->orderBy('id')->first();
        if (! $by) {
            $this->error('There is no Super Admin to act as.');

            return self::FAILURE;
        }
        auth()->setUser($by);

        $vehicles = Asset::vehicles()->orderBy('id')->limit(6)->get();
        $drivers = Contact::where('category', 'Drivers')->orderBy('id')->limit(6)->get();
        if ($vehicles->isEmpty()) {
            $this->error('There are no registered vehicles; add vehicles in Transport Routes first.');

            return self::FAILURE;
        }

        TransportNotifier::$muted = true;
        $made = ['transfer' => 0, 'own' => 0, 'passes' => 0, 'new_transfers' => 0];

        // ---- five from Transfers
        $already = TransportRecord::query()->whereNotNull('transfer_id')->pluck('transfer_id')->all();
        $liveTransfers = fn () => Transfer::withoutGlobalScopes()->whereNull('deleted_at')->where('draft', false)->whereIn('approval_status', [Transfer::APPROVAL_NOT_REQUIRED, Transfer::APPROVAL_APPROVED])
            ->whereNotNull('from_warehouse_id')->whereNotNull('to_warehouse_id')->whereNotIn('id', $already)->orderByDesc('id')->limit(40)->get()
            ->filter(fn ($t) => Warehouse::withoutGlobalScopes()->whereKey($t->from_warehouse_id)->whereNull('deleted_at')->exists() && Warehouse::withoutGlobalScopes()->whereKey($t->to_warehouse_id)->whereNull('deleted_at')->exists())
            ->take(5)->values();
        $transfers = $liveTransfers();
        // not enough real transfers: make the missing ones (marked SAMPLE, with one item, no stock moves)
        if ($transfers->count() < 5) {
            $made['new_transfers'] = $this->makeSampleTransfers(5 - $transfers->count(), $by);
            $transfers = $liveTransfers();
        }

        // price, trips, verify?
        $plan = [[85000, 1, true], [75000, 2, false], [120000, 1, true], [95000, 3, false], [65000, 1, false]];
        foreach ($transfers as $i => $t) {
            $pass = GatePass::where('source_type', GatePass::SOURCE_TRANSFER)->where('source_id', $t->id)->first();
            if (! $pass) {
                $pass = ReferenceLock::run('gate_passes', fn () => GatePass::create([
                    'reference' => GatePass::nextReference(), 'source_type' => GatePass::SOURCE_TRANSFER, 'source_id' => $t->id,
                    'asset_id' => $vehicles[$i % $vehicles->count()]->id, 'driver_contact_id' => $drivers->isEmpty() ? null : $drivers[$i % $drivers->count()]->id,
                    'driver_name' => $drivers->isEmpty() ? 'Sample Driver' : null, 'created_by_user_id' => $by->id, 'extra_attributes' => ['sample' => true],
                ]));
                $made['passes']++;
            } elseif (! $pass->asset_id) {
                continue;
            }
            [$price, $trips, $verify] = $plan[$i];
            $record = TransferRoute::generate($t, ['unit_price' => $price, 'trips_count' => $trips, 'trip_date' => TransferRoute::dateOf($t), 'notes' => self::MARK . ': generated from ' . $t->reference], $by);
            if ($verify) {
                $record->update(['verified_at' => now(), 'verified_by_user_id' => $by->id]);
            }
            $made['transfer']++;
        }

        // ---- five on their own
        $project = Warehouse::withoutGlobalScopes()->where('status', '!=', 'Main Warehouse')->orderBy('id')->first();
        $main = Warehouse::withoutGlobalScopes()->mainWarehouses()->orderBy('id')->first();
        $supplier = Contact::where('category', 'Suppliers')->orderBy('id')->first();
        $office = TransportLocation::orderBy('id')->first();
        $port = TransportLocation::where('location_type', 'Port')->first() ?: $office;
        $default = (float) transport_settings()['transport_default_price'];

        $own = [
            ['days' => 9, 'from' => ['Warehouse', $main], 'to' => ['Project', $project], 'unit' => $default, 'trips' => 2, 'state' => 'paid', 'note' => 'cement for the foundation'],
            ['days' => 7, 'from' => ['Supplier', $supplier], 'to' => ['Warehouse', $main], 'unit' => 110000, 'trips' => 1, 'state' => 'partial', 'note' => 'steel bars from the supplier'],
            ['days' => 5, 'from' => ['Warehouse', $main], 'to' => ['Port', $port], 'unit' => 90000, 'trips' => 1, 'state' => 'verified', 'note' => 'equipment to the port'],
            ['days' => 3, 'from' => ['Project', $project], 'to' => ['Office', $office], 'unit' => $default, 'trips' => 1, 'state' => 'unverified', 'note' => 'documents and samples'],
            ['days' => 2, 'from' => ['Warehouse', $main], 'to' => ['Project', $project], 'unit' => 70000, 'trips' => 4, 'state' => 'cancelled', 'note' => 'cancelled, the site was closed'],
        ];
        foreach ($own as $i => $o) {
            if (! $o['from'][1] || ! $o['to'][1]) {
                continue;
            }
            $this->ownTrip($o, $vehicles[$i % $vehicles->count()], $drivers->isEmpty() ? null : $drivers[($i + 2) % $drivers->count()], $by, $default);
            $made['own']++;
        }

        TransportNotifier::$muted = false;
        $this->info("Added {$made['transfer']} trip(s) generated from Transfers (with {$made['passes']} sample Gate Pass(es) and {$made['new_transfers']} sample Transfer(s) made for them) and {$made['own']} trip(s) logged on their own.");

        return self::SUCCESS;
    }

    /** Sample Transfers for the trips to hang on: a draft-free, approved transfer with one item. Nothing is stocked or moved. */
    private function makeSampleTransfers(int $n, User $by): int
    {
        $main = Warehouse::withoutGlobalScopes()->mainWarehouses()->whereNull('deleted_at')->orderBy('id')->get();
        $projects = Warehouse::withoutGlobalScopes()->whereNull('deleted_at')->where('status', '!=', 'Main Warehouse')->orderBy('id')->get();
        $item = \App\Models\Item::query()->orderBy('id')->first();
        if ($projects->count() < 2 || ! $item) {
            return 0;
        }
        $made = 0;
        for ($i = 0; $i < $n; $i++) {
            $fromMain = $main->isNotEmpty() && $i % 2 === 0;
            $from = $fromMain ? $main[$i % $main->count()] : $projects[$i % $projects->count()];
            $to = $projects[($i + 1) % $projects->count()];
            if ($from->id === $to->id) {
                $to = $projects[($i + 2) % $projects->count()];
            }
            $t = Transfer::create([
                'date' => now()->subDays(4 - $i)->format('Y-m-d'), 'reference' => 'SMP-TR-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'draft' => false,
                'approval_status' => $fromMain ? Transfer::APPROVAL_APPROVED : Transfer::APPROVAL_NOT_REQUIRED, 'approved_by' => $fromMain ? $by->id : null, 'approved_at' => $fromMain ? now() : null,
                'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'user_id' => $by->id, 'account_id' => $by->account_id,
                'details' => self::MARK . ': sample transfer made for the Transport Routes samples',
            ]);
            \App\Models\TransferItem::create(['transfer_id' => $t->id, 'item_id' => $item->id, 'quantity' => 10 + $i, 'unit_id' => $item->unit_id ?? null, 'account_id' => $by->account_id, 'draft' => false, 'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id]);
            $made++;
        }

        return $made;
    }

    private function ownTrip(array $o, Asset $vehicle, ?Contact $driver, User $by, float $default): void
    {
        [$fromType, $from] = $o['from'];
        [$toType, $to] = $o['to'];
        $total = round($o['unit'] * $o['trips'], 2);
        $overridden = abs($o['unit'] - $default) > 0.001;
        $paid = match ($o['state']) { 'paid' => $total, 'partial' => round($total / 2, 2), default => 0 };
        $verified = in_array($o['state'], ['paid', 'partial', 'verified'], true);

        $place = function (string $type, $model, string $prefix) {
            return [
                $prefix . 'type' => $type,
                ($prefix === 'departure_' ? 'departure_warehouse_id' : 'warehouse_id') => $model instanceof Warehouse ? $model->id : null,
                ($prefix === 'departure_' ? 'departure_contact_id' : 'destination_contact_id') => $model instanceof Contact ? $model->id : null,
                ($prefix === 'departure_' ? 'departure_transport_location_id' : 'transport_location_id') => $model instanceof TransportLocation ? $model->id : null,
                $prefix . 'label' => $model->name,
            ];
        };
        $dep = $place($fromType, $from, 'departure_');
        $dest = $place($toType, $to, 'destination_');

        ReferenceLock::run('transport_records', fn () => TransportRecord::create([
            'trip_number' => TransportRecord::nextTripNumber(),
            'trip_date' => now()->subDays($o['days'])->format('Y-m-d'),
            'asset_id' => $vehicle->id,
            'driver_contact_id' => $driver?->id,
            'default_amount' => $default, 'trips_count' => $o['trips'], 'unit_amount' => $o['unit'], 'amount' => $total,
            'amount_overridden' => $overridden,
            'amount_change_reason' => $overridden ? self::MARK . ': agreed price for this load' : null,
            'amount_changed_by_user_id' => $overridden ? $by->id : null, 'amount_changed_at' => $overridden ? now() : null,
            'payment_status' => $o['state'] === 'cancelled' ? TransportRecord::STATUS_CANCELLED : ($paid <= 0 ? TransportRecord::STATUS_UNPAID : ($paid >= $total ? TransportRecord::STATUS_PAID : TransportRecord::STATUS_PARTIALLY_PAID)),
            'paid_amount' => $paid,
            'payment_date' => $paid > 0 ? now()->subDays(max(0, $o['days'] - 1))->format('Y-m-d') : null,
            'payment_method' => $paid > 0 ? 'Cash' : null,
            'paid_by_user_id' => $paid > 0 ? $by->id : null,
            'verified_at' => $verified ? now()->subDays($o['days'])->addHour() : null,
            'verified_by_user_id' => $verified ? $by->id : null,
            'purpose' => ucfirst($o['note']),
            'notes' => self::MARK . ': ' . $o['note'],
            'created_by_user_id' => $by->id,
        ] + $dep + $dest));
    }

    private function remove(): int
    {
        $trips = TransportRecord::withTrashed()->where('notes', 'like', self::MARK . '%')->get();
        $n = $trips->count();
        foreach ($trips as $t) {
            $t->forceDelete();
        }
        $passes = GatePass::query()->where('extra_attributes', 'like', '%"sample":true%')->get();
        $p = $passes->count();
        foreach ($passes as $g) {
            $g->forceDelete();
        }
        $sampleTransfers = Transfer::withoutGlobalScopes()->withTrashed()->where('reference', 'like', 'SMP-TR-%')->where('details', 'like', self::MARK . '%')->get();
        $s = $sampleTransfers->count();
        foreach ($sampleTransfers as $t) {
            GatePass::where('source_type', GatePass::SOURCE_TRANSFER)->where('source_id', $t->id)->get()->each->forceDelete();
            \App\Models\TransferItem::withTrashed()->where('transfer_id', $t->id)->get()->each->forceDelete();
            $t->forceDelete();
        }
        $this->info("Removed {$n} sample trip(s), {$p} sample Gate Pass(es) and {$s} sample Transfer(s).");

        return self::SUCCESS;
    }
}
