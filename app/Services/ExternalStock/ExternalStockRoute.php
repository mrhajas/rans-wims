<?php

namespace App\Services\ExternalStock;

use App\Models\ExternalStockMovement;
use App\Models\GatePass;
use App\Models\TransportRecord;
use App\Models\Warehouse;
use App\Services\TransportNotifier;
use App\Support\ReferenceLock;
use Illuminate\Support\Facades\DB;

/**
 * External Stock <-> Transport Routes. An approved Stock Out or Stock In that has a Gate Pass already knows the driver, the vehicle and the two ends of the
 * trip, so the trip is logged straight from it: the user only gives the price of one trip and the number of trips. It is saved waiting for verification,
 * with transport_records.external_movement_id pointing back at the movement. Opening stock is not a trip and cannot have one.
 */
class ExternalStockRoute
{
    public static function activeRoute(int $movementId): ?TransportRecord
    {
        return TransportRecord::query()->where('external_movement_id', $movementId)->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->latest('id')->first();
    }

    public static function stateFor(ExternalStockMovement $m, ?array $gatePass = null): array
    {
        $routes = TransportRecord::query()->where('external_movement_id', $m->id)->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->latest('id')->get();

        return self::buildState($m, $gatePass ?? \App\Http\Controllers\GatePassController::stateFor($m, GatePass::SOURCE_EXTERNAL), $routes);
    }

    public static function statesFor($movements, array $gatePasses): array
    {
        $movements = collect($movements);
        $routes = TransportRecord::query()->whereIn('external_movement_id', $movements->pluck('id'))->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->latest('id')->get()->groupBy('external_movement_id');

        return $movements->mapWithKeys(fn ($m) => [$m->id => self::buildState($m, $gatePasses[$m->id] ?? null, $routes->get($m->id, collect()))])->all();
    }

    /** The default trip price from the transport settings, read once per request (not once per row). */
    private static function defaultPrice(): float
    {
        static $price = null;

        return $price ??= (float) transport_settings()['transport_default_price'];
    }

    private static function buildState(ExternalStockMovement $m, ?array $gatePass, $routes): array
    {
        $user = auth()->user();
        $route = $routes->first();
        $canGenerate = $user->can('generate-transport-route-external-stock');
        $hasPass = (bool) ($gatePass['exists'] ?? false);
        $blocked = null;
        if (! $route && $canGenerate) {
            $blocked = match (true) {
                $m->kind === 'opening' => __('Opening stock is not a trip, so it has no Transport Route.'),
                $m->status !== ExternalStockMovement::APPROVED => __('This movement has not been approved, so it cannot have a Transport Route.'),
                ! $hasPass => __('Generate the Gate Pass first: it holds the driver and the vehicle for the trip.'),
                empty($gatePass['details']['asset_id']) => __('The Gate Pass vehicle is not a registered vehicle. Change the Gate Pass to a registered vehicle first.'),
                default => null,
            };
        }
        $wh = $m->relationLoaded('warehouse') ? $m->warehouse?->name : Warehouse::withoutGlobalScopes()->find($m->warehouse_id)?->name;
        $person = $m->relationLoaded('contact') ? $m->contact?->name : optional(\App\Models\Contact::withTrashed()->find($m->contact_id))->name;

        return [
            'route' => $route && $user->can('read-transport-records') ? [
                'id' => $route->id, 'trip_number' => $route->trip_number, 'url' => route('transport-records.show', $route->id), 'verified' => (bool) $route->verified_at,
                'status' => $route->payment_status, 'trips' => (int) $route->trips_count, 'amount' => (float) $route->amount, 'more' => max(0, $routes->count() - 1),
            ] : null,
            'exists' => (bool) $route,
            'can_generate' => ! $route && $canGenerate && ! $blocked,
            'blocked' => $blocked,
            'generate_url' => route('external-stock.transport-route.store', $m->id),
            'transfer_reference' => $m->reference,
            'defaults' => ['trip_date' => $m->date->format('Y-m-d'), 'unit_price' => self::defaultPrice(), 'trips_count' => 1],
            'from' => $m->kind === 'withdrawal' ? $wh : $person,
            'to' => $m->kind === 'withdrawal' ? $person : $wh,
            'driver' => $gatePass['details']['driver_label'] ?? null,
            'vehicle' => $gatePass['details']['vehicle_label'] ?? null,
        ];
    }

    /** Logs the trip. Throws an HttpException (422) when the movement cannot have one. */
    public static function generate(ExternalStockMovement $m, array $data, $user): TransportRecord
    {
        abort_if($m->trashed(), 422, __('This movement was deleted, so it cannot have a Transport Route.'));
        abort_if($m->kind === 'opening', 422, __('Opening stock is not a trip, so it has no Transport Route.'));
        abort_if($m->status !== ExternalStockMovement::APPROVED, 422, __('This movement has not been approved, so it cannot have a Transport Route.'));
        $pass = GatePass::with(['driver', 'asset'])->where('source_type', GatePass::SOURCE_EXTERNAL)->where('source_id', $m->id)->first();
        abort_unless($pass, 422, __('Generate the Gate Pass first: it holds the driver and the vehicle for the trip.'));
        abort_unless($pass->asset_id && $pass->asset, 422, __('The Gate Pass vehicle is not a registered vehicle. Change the Gate Pass to a registered vehicle first.'));

        $wh = Warehouse::withoutGlobalScopes()->find($m->warehouse_id);
        $person = \App\Models\Contact::withTrashed()->find($m->contact_id);
        abort_unless($wh && $person, 422, __('The movement has no warehouse or person.'));
        $loc = $m->location ?: $person->location;
        $personLabel = mb_substr($person->name . ($loc ? ' - ' . $loc : ''), 0, 190);
        $whKind = Warehouse::withoutGlobalScopes()->whereKey($wh->id)->mainWarehouses()->exists() ? TransportRecord::DESTINATION_WAREHOUSE : TransportRecord::DESTINATION_PROJECT;
        $out = $m->kind === 'withdrawal';

        $unit = round((float) $data['unit_price'], 2);
        $trips = max(1, min(TransportRecord::MAX_TRIPS, (int) $data['trips_count']));
        $default = (float) transport_settings()['transport_default_price'];
        $overridden = abs($unit - $default) > 0.001;
        $driverNote = (! $pass->driver_contact_id && $pass->driver_name) ? __('Driver (typed on the Gate Pass): :d', ['d' => trim($pass->driver_name . ' ' . $pass->driver_phone)]) : null;
        $notes = collect([$data['notes'] ?? null, $driverNote, $m->collector_name ? __('Collected by :n (:p)', ['n' => $m->collector_name, 'p' => $m->collector_phone]) : null])->filter()->implode("\n");

        $record = DB::transaction(function () use ($m, $pass, $wh, $person, $personLabel, $whKind, $out, $unit, $trips, $default, $overridden, $notes, $data, $user) {
            ExternalStockMovement::withoutGlobalScopes()->whereKey($m->id)->lockForUpdate()->first();
            abort_if(self::activeRoute($m->id), 422, __('A Transport Route was already generated for this movement.'));
            $place = $out
                ? ['departure_type' => $whKind, 'departure_warehouse_id' => $wh->id, 'departure_label' => $wh->name, 'destination_type' => TransportRecord::DESTINATION_CLIENT, 'destination_contact_id' => $person->id, 'destination_label' => $personLabel]
                : ['departure_type' => TransportRecord::DESTINATION_CLIENT, 'departure_contact_id' => $person->id, 'departure_label' => $personLabel, 'destination_type' => $whKind, 'warehouse_id' => $wh->id, 'destination_label' => $wh->name];

            return ReferenceLock::run('transport_records', fn () => TransportRecord::create($place + [
                'trip_number' => TransportRecord::nextTripNumber(), 'external_movement_id' => $m->id, 'trip_date' => ($data['trip_date'] ?? null) ?: $m->date->format('Y-m-d'),
                'asset_id' => $pass->asset_id, 'driver_contact_id' => $pass->driver_contact_id, 'is_campus' => false, 'delivery_note_ref' => $m->reference,
                'purpose' => __('External Stock :ref (:p)', ['ref' => $m->reference, 'p' => $person->name]),
                'default_amount' => $default, 'trips_count' => $trips, 'unit_amount' => $unit, 'amount' => round($unit * $trips, 2), 'amount_overridden' => $overridden,
                'amount_change_reason' => $overridden ? __('Price entered when the route was generated from External Stock :ref.', ['ref' => $m->reference]) : null,
                'amount_changed_by_user_id' => $overridden ? $user->id : null, 'amount_changed_at' => $overridden ? now() : null,
                'payment_status' => TransportRecord::STATUS_UNPAID, 'notes' => $notes ?: null, 'created_by_user_id' => $user->id,
            ]));
        });

        log_activity(__('Transport Record generated from External Stock :ref.', ['ref' => $m->reference]), ['trip_number' => $record->trip_number, 'movement' => $m->reference, 'amount' => $record->amount, 'trips' => $record->trips_count], $record, 'Transport Record');
        log_activity(__('Transport Route :trip generated.', ['trip' => $record->trip_number]), ['trip_number' => $record->trip_number, 'amount' => $record->amount, 'trips' => $record->trips_count], $m, 'External Stock');
        TransportNotifier::notify('transport-trip-from-transfer', __('Transport Route generated from External Stock'),
            __(':trip (:trips x :unit = :total) was generated from External Stock :ref, :from to :to, by :user. It is waiting for verification.', [
                'trip' => $record->trip_number, 'trips' => $record->trips_count, 'unit' => number_format($unit, 2), 'total' => number_format((float) $record->amount, 2), 'ref' => $m->reference,
                'from' => $out ? $wh->name : $person->name, 'to' => $out ? $person->name : $wh->name, 'user' => $user->name]),
            route('transport-records.show', $record->id), $record, $user->id,
            array_filter([__('Trip') => $record->trip_number, __('External Stock') => $m->reference, __('Driver') => $pass->driverDisplay()['name'] ?? null, __('Amount') => number_format((float) $record->amount, 2)]));

        return $record;
    }
}
