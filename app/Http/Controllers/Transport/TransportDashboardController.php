<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\TransportRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TransportDashboardController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-transport-dashboard');

        $vehicles = Asset::vehicles();
        $trips = TransportRecord::query();
        // Cancelled trips are voided, and unverified trips haven't been
        // confirmed real yet (see TransportRecordController::verify()) —
        // both are excluded from every money total below, so Total Amount
        // always equals Total Paid + Total Outstanding. Trip *counts*
        // (total_trips, trips_today, etc.) intentionally still include both,
        // same as they already did for Cancelled — this only ever touches
        // money.
        $activeTrips = (clone $trips)
            ->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)
            ->whereNotNull('verified_at');

        $cards = [
            'total_vehicles' => (clone $vehicles)->count(),
            'active_vehicles' => (clone $vehicles)->where('status', 'Active')->count(),
            'total_trips' => (int) (clone $trips)->sum('trips_count'),
            'trips_today' => (int) (clone $trips)->whereDate('trip_date', now()->toDateString())->sum('trips_count'),
            'trips_this_month' => (int) (clone $trips)->whereYear('trip_date', now()->year)->whereMonth('trip_date', now()->month)->sum('trips_count'),
            'total_amount' => (float) (clone $activeTrips)->sum('amount'),
            'total_paid' => (float) (clone $activeTrips)->sum('paid_amount'),
            'total_outstanding' => (float) (clone $activeTrips)->sum(DB::raw('amount - paid_amount')),
            'unpaid_trips' => (int) (clone $trips)->where('payment_status', TransportRecord::STATUS_UNPAID)->sum('trips_count'),
            'unverified_trips' => (int) (clone $trips)->whereNull('verified_at')->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->sum('trips_count'),
            'trips_to_projects' => (int) (clone $trips)->where('destination_type', TransportRecord::DESTINATION_PROJECT)->sum('trips_count'),
            'trips_to_warehouses' => (int) (clone $trips)->whereIn('destination_type', [TransportRecord::DESTINATION_WAREHOUSE, TransportRecord::DESTINATION_MIXED])->sum('trips_count'),
            'trips_to_other' => (int) (clone $trips)->whereNotIn('destination_type', [...TransportRecord::WAREHOUSE_DESTINATION_TYPES, TransportRecord::DESTINATION_MIXED])->sum('trips_count'),
        ];

        $mostActiveVehicles = (clone $activeTrips)
            ->select('asset_id', DB::raw('SUM(trips_count) as trip_count'), DB::raw('SUM(amount) as total_amount'))
            ->with('asset:id,name,registration_number')
            ->groupBy('asset_id')
            ->orderByDesc('trip_count')
            ->limit(5)
            ->get();

        $mostFrequentDestinations = (clone $activeTrips)
            ->select('destination_label', 'destination_type', DB::raw('SUM(trips_count) as trip_count'))
            ->whereNotNull('destination_label')
            ->groupBy('destination_label', 'destination_type')
            ->orderByDesc('trip_count')
            ->limit(5)
            ->get();

        $costByProjectWarehouse = (clone $activeTrips)
            ->select('warehouse_id', DB::raw('SUM(amount) as total_amount'), DB::raw('SUM(trips_count) as trip_count'))
            ->whereNotNull('warehouse_id')
            ->with('warehouse:id,name')
            ->groupBy('warehouse_id')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        $costByDriver = (clone $activeTrips)
            ->select('driver_contact_id', DB::raw('SUM(amount) as total_amount'), DB::raw('SUM(trips_count) as trip_count'))
            ->whereNotNull('driver_contact_id')
            ->with('driver:id,name')
            ->groupBy('driver_contact_id')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        return Inertia::render('TransportManagement/Dashboard', [
            'cards' => $cards,
            'mostActiveVehicles' => $mostActiveVehicles,
            'mostFrequentDestinations' => $mostFrequentDestinations,
            'costByProjectWarehouse' => $costByProjectWarehouse,
            'costByDriver' => $costByDriver,
        ]);
    }
}
