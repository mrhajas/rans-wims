<?php

namespace App\Http\Controllers\ExternalStock;

use App\Http\Controllers\Controller;
use App\Models\ExternalStockMovement;
use App\Models\TransportRecord;
use App\Services\ExternalStock\ExternalStockRoute;
use Illuminate\Http\Request;

/** External Stock > "Generate Transport Route": the trip of an approved movement, from its Gate Pass (driver, vehicle) and its two ends. */
class RouteController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function store(Request $request, ExternalStockMovement $movement)
    {
        abort_unless($request->user()->can('generate-transport-route-external-stock'), 403);
        abort_unless(ExternalStockMovement::query()->visibleTo($request->user())->whereKey($movement->id)->exists(), 404);
        $data = $request->validate(['unit_price' => 'required|numeric|min:0|max:999999999', 'trips_count' => 'required|integer|min:1|max:' . TransportRecord::MAX_TRIPS, 'trip_date' => 'nullable|date', 'notes' => 'nullable|string|max:1000']);
        $record = ExternalStockRoute::generate($movement, $data, $request->user());

        return back()->with('message', __('Transport Route :trip generated. It is waiting for verification.', ['trip' => $record->trip_number]));
    }
}
