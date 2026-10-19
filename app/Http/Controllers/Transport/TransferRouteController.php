<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use App\Models\TransportRecord;
use App\Services\Transport\TransferRoute;
use Illuminate\Http\Request;

/** Transfers > "Generate Transport Route": logs the Transport Routes trip for a Transfer from its Gate Pass (driver, vehicle) and its from / to. */
class TransferRouteController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]); // gated explicitly below
    }

    public function store(Request $request, Transfer $transfer)
    {
        abort_unless($request->user()->can('generate-transport-route-transfers'), 403);

        $data = $request->validate([
            'unit_price' => 'required|numeric|min:0|max:999999999',
            'trips_count' => 'required|integer|min:1|max:' . TransportRecord::MAX_TRIPS,
            'trip_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $record = TransferRoute::generate($transfer, $data, $request->user());

        return back()->with('message', __('Transport Route :trip generated. It is waiting for verification.', ['trip' => $record->trip_number]));
    }
}
