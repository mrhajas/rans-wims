<?php

namespace App\Services\Transport;

use App\Models\GatePass;
use App\Models\Transfer;
use App\Models\TransportRecord;
use App\Models\Warehouse;
use App\Services\TransportNotifier;
use App\Support\ReferenceLock;
use Illuminate\Support\Facades\DB;

/**
 * Transfer <-> Transport Routes link. A Transfer that has a Gate Pass already knows the driver, the vehicle and where the goods go
 * from and to, so the trip can be logged straight from it: the user only gives the price of one trip and the number of trips.
 * The trip is saved waiting for verification (unverified) with transport_records.transfer_id pointing back at the Transfer.
 */
class TransferRoute
{
    /** The transfer's date as Y-m-d (it is stored as plain text), or today. */
    public static function dateOf($transfer): string
    {
        try {
            return $transfer->date ? \Carbon\Carbon::parse($transfer->date)->format('Y-m-d') : now()->format('Y-m-d');
        } catch (\Throwable) {
            return now()->format('Y-m-d');
        }
    }

    /** The trips already made for a transfer (a cancelled trip no longer counts). */
    public static function activeRoute(int $transferId): ?TransportRecord
    {
        return TransportRecord::query()->where('transfer_id', $transferId)->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->latest('id')->first();
    }

    /** What the Transfer pages need to show the "Generate Transport Route" button, or the link to the trip that was generated. */
    public static function stateFor(Transfer $transfer, ?array $gatePass = null): array
    {
        $routes = TransportRecord::query()->where('transfer_id', $transfer->id)->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->latest('id')->get();

        return self::buildState($transfer, $gatePass ?? \App\Http\Controllers\GatePassController::stateFor($transfer, GatePass::SOURCE_TRANSFER), $routes);
    }

    /** The same for a whole page of transfers (one query for the trips; gate pass states are passed in, already built). */
    public static function statesFor($transfers, array $gatePasses): array
    {
        $transfers = collect($transfers);
        $routes = TransportRecord::query()->whereIn('transfer_id', $transfers->pluck('id'))->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->latest('id')->get()->groupBy('transfer_id');

        return $transfers->mapWithKeys(fn ($t) => [$t->id => self::buildState($t, $gatePasses[$t->id] ?? null, $routes->get($t->id, collect()))])->all();
    }

    /** @param  Transfer|\App\Http\Resources\TransferResource  $transfer  (a list page hands over the resources it is about to render) */
    private static function buildState($transfer, ?array $gatePass, $routes): array
    {
        $user = auth()->user();
        $route = $routes->first();
        $canRead = $user->can('read-transport-records');
        $canGenerate = $user->can('generate-transport-route-transfers');
        $hasPass = (bool) ($gatePass['exists'] ?? false);

        $blocked = null;
        if (! $route && $canGenerate) {
            $blocked = match (true) {
                $transfer->trashed() => __('This transfer was deleted, so it cannot have a Transport Route.'),
                $transfer->draft => __('A draft transfer cannot have a Transport Route yet.'),
                in_array($transfer->approval_status, [Transfer::APPROVAL_PENDING, Transfer::APPROVAL_CANCELLED], true) => __('This transfer has not been approved, so it cannot have a Transport Route.'),
                ! $hasPass => __('Generate the Gate Pass first: it holds the driver and the vehicle for the trip.'),
                empty($gatePass['details']['asset_id']) => __('The Gate Pass vehicle is not a registered vehicle. Change the Gate Pass to a registered vehicle first.'),
                default => null,
            };
        }

        return [
            'route' => $route && $canRead ? [
                'id' => $route->id, 'trip_number' => $route->trip_number, 'url' => route('transport-records.show', $route->id),
                'verified' => (bool) $route->verified_at, 'status' => $route->payment_status, 'trips' => (int) $route->trips_count,
                'amount' => (float) $route->amount, 'more' => max(0, $routes->count() - 1),
            ] : null,
            'exists' => (bool) $route,
            'can_generate' => ! $route && $canGenerate && ! $blocked,
            'blocked' => $blocked,
            'generate_url' => route('transfers.transport-route.store', $transfer->id),
            'transfer_reference' => $transfer->reference,
            'defaults' => [
                'trip_date' => self::dateOf($transfer),
                'unit_price' => (float) transport_settings()['transport_default_price'],
                'trips_count' => 1,
            ],
            'from' => $transfer->relationLoaded('fromWarehouse') ? $transfer->fromWarehouse?->name : Warehouse::withoutGlobalScopes()->find($transfer->from_warehouse_id)?->name,
            'to' => $transfer->relationLoaded('toWarehouse') ? $transfer->toWarehouse?->name : Warehouse::withoutGlobalScopes()->find($transfer->to_warehouse_id)?->name,
            'driver' => $gatePass['details']['driver_label'] ?? null,
            'vehicle' => $gatePass['details']['vehicle_label'] ?? null,
        ];
    }

    /**
     * Logs the trip. Throws an HttpException (422) when the transfer cannot have one.
     *
     * @param  array{unit_price:float|int|string, trips_count:int, trip_date?:?string, notes?:?string}  $data
     */
    public static function generate(Transfer $transfer, array $data, $user): TransportRecord
    {
        abort_if($transfer->trashed(), 422, __('This transfer was deleted, so it cannot have a Transport Route.'));
        abort_if($transfer->draft, 422, __('A draft transfer cannot have a Transport Route yet.'));
        abort_if(in_array($transfer->approval_status, [Transfer::APPROVAL_PENDING, Transfer::APPROVAL_CANCELLED], true), 422, __('This transfer has not been approved, so it cannot have a Transport Route.'));

        $pass = GatePass::with(['driver', 'asset'])->where('source_type', GatePass::SOURCE_TRANSFER)->where('source_id', $transfer->id)->first();
        abort_unless($pass, 422, __('Generate the Gate Pass first: it holds the driver and the vehicle for the trip.'));
        abort_unless($pass->asset_id && $pass->asset, 422, __('The Gate Pass vehicle is not a registered vehicle. Change the Gate Pass to a registered vehicle first.'));

        $from = Warehouse::withoutGlobalScopes()->find($transfer->from_warehouse_id);
        $to = Warehouse::withoutGlobalScopes()->find($transfer->to_warehouse_id);
        abort_unless($from && $to, 422, __('The transfer has no from / to place.'));
        $isMain = fn (Warehouse $w) => Warehouse::withoutGlobalScopes()->whereKey($w->id)->mainWarehouses()->exists();
        $kind = fn (Warehouse $w) => $isMain($w) ? TransportRecord::DESTINATION_WAREHOUSE : TransportRecord::DESTINATION_PROJECT;

        $unit = round((float) $data['unit_price'], 2);
        $trips = max(1, min(TransportRecord::MAX_TRIPS, (int) $data['trips_count']));
        $default = (float) transport_settings()['transport_default_price'];
        $overridden = abs($unit - $default) > 0.001;

        $driverNote = (! $pass->driver_contact_id && $pass->driver_name) ? __('Driver (typed on the Gate Pass): :d', ['d' => trim($pass->driver_name . ' ' . $pass->driver_phone)]) : null;
        $notes = collect([$data['notes'] ?? null, $driverNote])->filter()->implode("\n");

        $record = DB::transaction(function () use ($transfer, $pass, $from, $to, $kind, $unit, $trips, $default, $overridden, $notes, $data, $user) {
            // one live trip per transfer: a second click (or a second user) is refused, a cancelled trip may be replaced
            Transfer::withoutGlobalScopes()->whereKey($transfer->id)->lockForUpdate()->first();
            abort_if(self::activeRoute($transfer->id), 422, __('A Transport Route was already generated for this transfer.'));

            return ReferenceLock::run('transport_records', fn () => TransportRecord::create([
                'trip_number' => TransportRecord::nextTripNumber(),
                'transfer_id' => $transfer->id,
                'trip_date' => ($data['trip_date'] ?? null) ?: self::dateOf($transfer),
                'asset_id' => $pass->asset_id,
                'driver_contact_id' => $pass->driver_contact_id,
                'departure_type' => $kind($from),
                'departure_warehouse_id' => $from->id,
                'departure_label' => $from->name,
                'destination_type' => $kind($to),
                'warehouse_id' => $to->id,
                'destination_label' => $to->name,
                'delivery_note_ref' => $transfer->reference,
                'purpose' => __('Transfer :ref', ['ref' => $transfer->reference]),
                'is_campus' => false,
                'default_amount' => $default,
                'trips_count' => $trips,
                'unit_amount' => $unit,
                'amount' => round($unit * $trips, 2),
                'amount_overridden' => $overridden,
                'amount_change_reason' => $overridden ? __('Price entered when the route was generated from Transfer :ref.', ['ref' => $transfer->reference]) : null,
                'amount_changed_by_user_id' => $overridden ? $user->id : null,
                'amount_changed_at' => $overridden ? now() : null,
                'payment_status' => TransportRecord::STATUS_UNPAID,
                'notes' => $notes ?: null,
                'created_by_user_id' => $user->id,
            ]));
        });

        log_activity(__('Transport Record generated from Transfer :ref.', ['ref' => $transfer->reference]), [
            'trip_number' => $record->trip_number, 'transfer' => $transfer->reference, 'amount' => $record->amount, 'trips' => $record->trips_count,
        ], $record, 'Transport Record');
        log_activity(__('Transport Route :trip generated.', ['trip' => $record->trip_number]), ['trip_number' => $record->trip_number, 'amount' => $record->amount, 'trips' => $record->trips_count], $transfer, 'Transfer');

        TransportNotifier::notify(
            'transport-trip-from-transfer',
            __('Transport Route generated from a Transfer'),
            __(':trip (:trips x :unit = :total) was generated from Transfer :ref, :from to :to, by :user. It is waiting for verification.', [
                'trip' => $record->trip_number, 'trips' => $record->trips_count, 'unit' => number_format($unit, 2), 'total' => number_format((float) $record->amount, 2),
                'ref' => $transfer->reference, 'from' => $from->name, 'to' => $to->name, 'user' => $user->name,
            ]),
            route('transport-records.show', $record->id),
            $record,
            $user->id,
            array_filter([
                __('Trip') => $record->trip_number, __('Transfer') => $transfer->reference, __('From') => $from->name, __('To') => $to->name,
                __('Vehicle') => trim(($pass->vehicleDisplay()['plate'] ?? '') . ' ' . ($pass->vehicleDisplay()['details'] ?? '')),
                __('Driver') => $pass->driverDisplay()['name'] ?? null, __('Amount') => number_format((float) $record->amount, 2),
            ])
        );

        return $record;
    }
}
