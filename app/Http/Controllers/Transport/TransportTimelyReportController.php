<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\TransportRecord;
use App\Services\Transport\LoadingLedger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * A weekly-style, per-vehicle report: every vehicle in the fleet, each
 * one's trips within the selected date range, and totals scoped strictly to
 * that range — distinct from TransportReportController's single flexible
 * report_type-driven table, which never groups by vehicle AND lists each
 * vehicle's own trip detail at the same time.
 */
class TransportTimelyReportController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-transport-timely-reports');

        $filters = $this->filters($request);
        $vehicles = $this->vehicleReport($filters);

        return Inertia::render('TransportManagement/Reports/Timely', [
            'filters' => $filters,
            'vehicles' => $vehicles,
            'totals' => $this->grandTotals($vehicles),
            'loading' => $this->loadingSection($request, $filters),
            'canLoadingReport' => $request->user()->can('read-transport-loading-reports'),
        ]);
    }

    public function printList(Request $request)
    {
        $this->authorize('print-transport-records');

        return view('reports.transport-timely-pdf', $this->payload($request, false));
    }

    public function pdfList(Request $request)
    {
        $this->authorize('print-transport-records');

        return Pdf::loadView('reports.transport-timely-pdf', $this->payload($request, true))
            ->setPaper('a4', 'portrait')
            ->download('transport-timely-report-' . now()->format('Ymd-His') . '.pdf');
    }

    public function export(Request $request)
    {
        $this->authorize('export-transport-records');

        $filters = $this->filters($request);
        $vehicles = $this->vehicleReport($filters);

        $rows = [];
        foreach ($vehicles as $vehicle) {
            foreach ($vehicle['trips'] as $trip) {
                $rows[] = [
                    'Vehicle' => $vehicle['vehicle']['registration_number'] ?? $vehicle['vehicle']['name'],
                    'Trip Number' => $trip->trip_number,
                    'Date' => optional($trip->trip_date)->format('Y-m-d'),
                    'Driver' => $trip->driver?->name,
                    'Destination Type' => $trip->destination_type,
                    'Destination' => $trip->destination_label,
                    'Amount' => $trip->amount,
                    'Paid' => $trip->paid_amount,
                    'Outstanding' => $trip->outstanding_amount,
                    'Payment Status' => $trip->payment_status,
                    'Verified' => $trip->verified_at ? 'Yes' : 'No',
                ];
            }
        }

        $sheets = ['All Cars' => collect($rows)];
        if ($loading = $this->loadingSection($request, $filters)) {
            $sheets['Loading & Unloading'] = collect($loading['rows'])->map(fn ($r) => [
                'Worker' => $r['name'], 'Previous balance' => $r['opening'], 'Days' => $r['days'], 'Earned' => $r['earned'], 'Paid' => $r['paid'], 'Balance' => $r['balance'],
            ]);
        }

        return (new FastExcel(new \Rap2hpoutre\FastExcel\SheetCollection($sheets)))->download('transport-timely-report-' . now()->format('Ymd-His') . '.xlsx');
    }

    /** The Loading & Unloading section (table + cards) for the same period; null for people who may not see it. */
    private function loadingSection(Request $request, array $filters): ?array
    {
        if (! $request->user()->can('read-transport-loading')) {
            return null;
        }

        return LoadingLedger::reportSection($filters['date_from'] ?? null, $filters['date_to'] ?? null);
    }

    /** Defaults to the current week (Mon–Sun) — this is a weekly-style report, but any range can be picked. */
    private function filters(Request $request): array
    {
        return [
            'date_from' => $request->input('date_from') ?: now()->startOfWeek()->toDateString(),
            'date_to' => $request->input('date_to') ?: now()->endOfWeek()->toDateString(),
        ];
    }

    /**
     * One block per vehicle in the fleet — including one with zero trips
     * this period, so an idle vehicle is still visible in "All Cars". Every
     * total here (including total_outstanding) is scoped strictly to trips
     * within the selected date range — the vehicle's pre-existing opening
     * balance is deliberately NOT folded in (unlike
     * TransportVehicleController::show()'s all-time trip_stats), since this
     * report's Total Outstanding is meant to answer "what's outstanding
     * from this period" only. Money aggregates exclude Cancelled and
     * not-yet-verified trips, same rule as
     * TransportReportController::totals()/TransportDashboardController — a
     * voided trip never incurred a real cost, and an unverified one hasn't
     * been confirmed real yet. The raw trip list itself still shows every
     * trip regardless of status/verification, each flagged with its own
     * status/verified badge.
     */
    private function vehicleReport(array $filters): array
    {
        $vehicles = Asset::vehicles()->orderBy('name')->get(['id', 'name', 'registration_number', 'asset_number']);

        return $vehicles->map(function (Asset $vehicle) use ($filters) {
            $trips = TransportRecord::where('asset_id', $vehicle->id)
                ->filter($filters)
                ->with(['driver:id,name', 'verifiedBy:id,name'])
                ->orderBy('trip_date')
                ->orderBy('trip_time')
                ->get();

            $activeTrips = $trips->filter(
                fn (TransportRecord $trip) => $trip->payment_status !== TransportRecord::STATUS_CANCELLED && $trip->verified_at !== null
            );

            return [
                'vehicle' => [
                    'id' => $vehicle->id,
                    'name' => $vehicle->name,
                    'registration_number' => $vehicle->registration_number,
                    'asset_number' => $vehicle->asset_number,
                ],
                'trips' => $trips->values(),
                'stats' => [
                    'total_trips' => (int) $trips->sum('trips_count'),
                    'total_amount' => (float) $activeTrips->sum('amount'),
                    'total_paid' => (float) $activeTrips->sum('paid_amount'),
                    'total_outstanding' => (float) $activeTrips->sum(fn (TransportRecord $trip) => $trip->outstanding_amount),
                ],
            ];
        })->values()->all();
    }

    private function grandTotals(array $vehicles): array
    {
        $stats = array_column($vehicles, 'stats');

        return [
            'total_vehicles' => count($vehicles),
            'total_trips' => array_sum(array_column($stats, 'total_trips')),
            'total_amount' => array_sum(array_column($stats, 'total_amount')),
            'total_paid' => array_sum(array_column($stats, 'total_paid')),
            'total_outstanding' => array_sum(array_column($stats, 'total_outstanding')),
        ];
    }

    private function payload(Request $request, bool $isPdf): array
    {
        $filters = $this->filters($request);
        $vehicles = $this->vehicleReport($filters);

        return [
            'isPdf' => $isPdf,
            'companyName' => 'RANS Company Limited',
            'filters' => $filters,
            'vehicles' => $vehicles,
            'totals' => $this->grandTotals($vehicles),
            'loading' => $this->loadingSection($request, $filters),
            'generatedBy' => optional($request->user())->name ?? 'N/A',
            'generatedAt' => $generatedAt = now(),
            // Signatories: the person who generates the report signs as Prepared By (nothing is stored; inert unless a document setup exists)
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('transport_timely_report', \App\Services\Signatories\Documents\TransportTimelyReportDocument::record($request->user(), $generatedAt)),
            'autoPrint' => ! $isPdf,
        ];
    }
}
