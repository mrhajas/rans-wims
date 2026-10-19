<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransportRecordCollection;
use App\Models\Contact;
use App\Models\TransportRecord;
use App\Models\Warehouse;
use App\Services\Transport\LoadingLedger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * One flexible report page rather than 13 separate ones — report_type picks
 * the grouping/aggregation applied to the same base filtered query, styled
 * directly off PaymentVoucherController::transportFuelReport() (the
 * existing Transport/Fuel report already in this app). Totals always come
 * from a separate un-paginated aggregate query excluding Cancelled trips,
 * never summed from the paginated page.
 */
class TransportReportController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    const REPORT_TYPES = [
        'records', 'daily', 'monthly', 'vehicle', 'driver', 'project', 'warehouse',
        'destination', 'paid', 'unpaid', 'outstanding', 'cost_summary', 'vehicle_activity',
    ];

    public function index(Request $request)
    {
        $this->authorize('read-transport-records');

        $filters = $this->filters($request);

        return Inertia::render('TransportManagement/Reports/Index', [
            'filters' => $filters,
            'reportTypes' => self::REPORT_TYPES,
            'rows' => $this->rows($filters),
            'totals' => $this->totals($filters),
            'loading' => $this->loadingSection($request, $filters),
            'canLoadingReport' => $request->user()->can('read-transport-loading-reports'),
            'vehicles' => \App\Models\Asset::vehicles()->orderBy('name')->get(['id', 'name', 'registration_number']),
            'drivers' => Contact::where('category', 'Drivers')->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name']),
            'destinationTypes' => TransportRecord::DESTINATION_TYPES,
            'paymentStatuses' => TransportRecord::PAYMENT_STATUSES,
        ]);
    }

    public function printList(Request $request)
    {
        $this->authorize('print-transport-records');

        return view('reports.transport-pdf', $this->payload($request, false));
    }

    public function pdfList(Request $request)
    {
        $this->authorize('print-transport-records');

        return Pdf::loadView('reports.transport-pdf', $this->payload($request, true))
            ->setPaper('a4', 'landscape')
            ->download('transport-report-' . now()->format('Ymd-His') . '.pdf');
    }

    public function export(Request $request)
    {
        $this->authorize('export-transport-records');

        $filters = $this->filters($request);
        $rows = $this->baseQuery($filters)->with('transfer:id,reference,deleted_at')->orderBy('trip_date')->orderBy('id')->limit(10000)->get()->map(fn (TransportRecord $r) => [
            'Trip Number' => $r->trip_number,
            'Date' => optional($r->trip_date)->format('Y-m-d'),
            'Vehicle' => $r->asset?->registration_number ?? $r->asset?->name,
            'Driver' => $r->driver?->name,
            'From' => $r->departure_label,
            'Destination Type' => $r->destination_type,
            'Destination' => $r->destination_label,
            'Trips' => $r->trips_count,
            'Price per trip' => $r->unit_price,
            'Amount' => $r->amount,
            'Paid' => $r->paid_amount,
            'Outstanding' => $r->outstanding_amount,
            'Payment Status' => $r->payment_status,
            'Verified' => $r->verified_at ? 'Yes' : 'No',
            'Transfer' => $r->transfer?->reference,
        ]);

        $sheets = ['All Cars' => $rows];
        if ($loading = $this->loadingSection($request, $filters)) {
            $sheets['Loading & Unloading'] = collect($loading['rows'])->map(fn ($r) => [
                'Worker' => $r['name'], 'Previous balance' => $r['opening'], 'Days' => $r['days'], 'Earned' => $r['earned'], 'Paid' => $r['paid'], 'Balance' => $r['balance'],
            ]);
        }

        return (new FastExcel(new \Rap2hpoutre\FastExcel\SheetCollection($sheets)))->download('transport-report-' . now()->format('Ymd-His') . '.xlsx');
    }

    /** The Loading & Unloading section (table + cards) for the same period; null for people who may not see it. */
    private function loadingSection(Request $request, array $filters): ?array
    {
        if (! $request->user()->can('read-transport-loading')) {
            return null;
        }

        return LoadingLedger::reportSection($filters['date_from'] ?? null, $filters['date_to'] ?? null);
    }

    private function filters(Request $request): array
    {
        $filters = $request->only('report_type', 'date_from', 'date_to', 'asset_id', 'driver_contact_id', 'warehouse_id', 'destination_type', 'payment_status', 'sort', 'direction');
        $filters['report_type'] = in_array($filters['report_type'] ?? null, self::REPORT_TYPES, true) ? $filters['report_type'] : 'records';
        $filters['direction'] = ($filters['direction'] ?? null) === 'desc' ? 'desc' : 'asc';

        return $filters;
    }

    private function baseQuery(array $filters)
    {
        $query = TransportRecord::with(['asset:id,name,registration_number', 'driver:id,name', 'warehouse:id,name'])
            ->filter($filters);

        return match ($filters['report_type']) {
            'paid' => $query->where('payment_status', TransportRecord::STATUS_PAID),
            'unpaid' => $query->where('payment_status', TransportRecord::STATUS_UNPAID),
            'outstanding' => $query->whereIn('payment_status', [TransportRecord::STATUS_UNPAID, TransportRecord::STATUS_PARTIALLY_PAID, TransportRecord::STATUS_PENDING_PAYMENT]),
            // Project and Warehouse both resolve to a `warehouses` row (WIMS
            // has no separate Projects table) distinguished only by
            // destination_type — without this filter the two report types
            // grouped on the same warehouse_id column produced byte-identical
            // output.
            'project' => $query->where('destination_type', TransportRecord::DESTINATION_PROJECT),
            'warehouse' => $query->whereIn('destination_type', [TransportRecord::DESTINATION_WAREHOUSE, TransportRecord::DESTINATION_MIXED]),
            default => $query,
        };
    }

    /** cost_summary has no row list — totals only. Grouped report types return one aggregated row per group instead of raw trips. */
    private function rows(array $filters): array
    {
        if ($filters['report_type'] === 'cost_summary') {
            return [];
        }

        $grouped = match ($filters['report_type']) {
            'vehicle', 'vehicle_activity' => 'asset_id',
            'driver' => 'driver_contact_id',
            'project', 'warehouse' => 'warehouse_id',
            'destination' => 'destination_label',
            'daily' => DB::raw('DATE(trip_date)'),
            'monthly' => DB::raw("DATE_FORMAT(trip_date, '%Y-%m')"),
            default => null,
        };

        if ($grouped === null) {
            return $this->sortRawRows($this->baseQuery($filters)->with('transfer:id,reference,deleted_at'), $filters)->limit(500)->get()->values()->all();
        }

        $groupField = $filters['report_type'] === 'daily' ? 'trip_day' : ($filters['report_type'] === 'monthly' ? 'trip_month' : $grouped);
        $selectGroup = in_array($filters['report_type'], ['daily', 'monthly'], true)
            ? DB::raw(($filters['report_type'] === 'daily' ? 'DATE(trip_date)' : "DATE_FORMAT(trip_date, '%Y-%m')") . " as {$groupField}")
            : $grouped;

        // Grouped cost aggregates exclude Cancelled and not-yet-verified
        // trips, matching totals() below — a voided trip never incurred a
        // real cost, and an unverified one hasn't been confirmed real yet,
        // so neither should inflate a vehicle/driver/project's reported spend.
        $query = $this->baseQuery($filters)
            ->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)
            ->whereNotNull('verified_at');

        // A trip whose group key is NULL (e.g. a Supplier/Client/Office trip
        // has no warehouse_id) must not collapse into one bogus "group" —
        // Project/Warehouse/Driver/Destination reports were silently lumping
        // every such trip into a single fake row instead of just omitting it.
        if (is_string($grouped)) {
            $query->whereNotNull($grouped);
        }

        $selectColumns = [$selectGroup, DB::raw('SUM(trips_count) as trip_count'), DB::raw('SUM(amount) as total_amount'), DB::raw('SUM(paid_amount) as total_paid')];

        // 'destination' groups by destination_label alone, but the Vue page's
        // groupLabel() also needs destination_type to render "Label (Type)" —
        // select and group by it too so it isn't undefined for every row.
        if ($filters['report_type'] === 'destination') {
            $selectColumns[] = 'destination_type';
        }

        $query->select($selectColumns)
            ->groupBy($groupField)
            ->when($filters['report_type'] === 'destination', fn ($q) => $q->groupBy('destination_type'));

        return $this->sortGroupedRows($query, $filters, $groupField)
            ->limit(200)
            ->get()
            ->values()
            ->all();
    }

    // Mirrors the Vue page's own sortValue()/toggleSort() so the Print/PDF
    // output matches whatever order the user last clicked into on screen,
    // instead of always falling back to the query's default order — both
    // read the same $filters['sort']/['direction'] the page now appends to
    // its print/pdf/export links.
    private function sortRawRows($query, array $filters)
    {
        $sortKey = $filters['sort'] ?? null;
        $direction = $filters['direction'] ?? 'asc';

        $columns = [
            'group' => 'transport_records.trip_number',
            'date' => 'transport_records.trip_date',
            'destination' => 'transport_records.destination_label',
            'amount' => 'transport_records.amount',
            'paid' => 'transport_records.paid_amount',
            'status' => 'transport_records.payment_status',
            'verified' => 'transport_records.verified_at',
            'outstanding' => DB::raw('(transport_records.amount - transport_records.paid_amount)'),
        ];

        if ($sortKey === 'driver') {
            return $query->select('transport_records.*')
                ->leftJoin('contacts', 'contacts.id', '=', 'transport_records.driver_contact_id')
                ->orderBy('contacts.name', $direction)
                ->orderByDesc('transport_records.id');
        }

        if (isset($columns[$sortKey])) {
            return $query->orderBy($columns[$sortKey], $direction)->orderByDesc('transport_records.id');
        }

        return $query->orderByDesc('transport_records.trip_date')->orderByDesc('transport_records.id');
    }

    private function sortGroupedRows($query, array $filters, string $groupField)
    {
        $sortKey = $filters['sort'] ?? null;
        $direction = $filters['direction'] ?? 'asc';

        if ($sortKey === 'group') {
            return match ($filters['report_type']) {
                'vehicle', 'vehicle_activity' => $query->leftJoin('assets', 'assets.id', '=', 'transport_records.asset_id')->orderBy('assets.registration_number', $direction),
                'driver' => $query->leftJoin('contacts', 'contacts.id', '=', 'transport_records.driver_contact_id')->orderBy('contacts.name', $direction),
                'project', 'warehouse' => $query->leftJoin('warehouses', 'warehouses.id', '=', 'transport_records.warehouse_id')->orderBy('warehouses.name', $direction),
                default => $query->orderBy($groupField, $direction),
            };
        }

        if (in_array($sortKey, ['trip_count', 'total_amount', 'total_paid'], true)) {
            return $query->orderBy($sortKey, $direction);
        }

        if ($sortKey === 'outstanding') {
            return $query->orderByRaw('(SUM(amount) - SUM(paid_amount)) ' . ($direction === 'desc' ? 'desc' : 'asc'));
        }

        return $query->orderByDesc('total_amount');
    }

    private function totals(array $filters): array
    {
        $query = $this->baseQuery($filters)->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->whereNotNull('verified_at');

        // Trips that exist but are not counted above yet because nobody has verified them (every trip generated from a Transfer
        // starts here), and how many of the trips came from Transfers: shown beside the totals so nothing looks missing.
        $live = $this->baseQuery($filters)->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED);
        $pending = (clone $live)->whereNull('verified_at');
        $fromTransfers = (clone $live)->whereNotNull('transfer_id');

        return [
            'total_trips' => (int) (clone $query)->sum('trips_count'),
            'total_amount' => (float) (clone $query)->sum('amount'),
            'total_paid' => (float) (clone $query)->sum('paid_amount'),
            'total_outstanding' => (float) (clone $query)->sum(DB::raw('amount - paid_amount')),
            'pending_records' => (int) (clone $pending)->count(),
            'pending_amount' => (float) (clone $pending)->sum('amount'),
            'transfer_records' => (int) (clone $fromTransfers)->count(),
            'transfer_amount' => (float) (clone $fromTransfers)->sum('amount'),
        ];
    }

    // The on-screen page renders one of three shapes depending on
    // report_type — a grouped summary table, a raw trip list, or (for
    // cost_summary) totals only, no table — and until this rewrite
    // Print/PDF always rendered a raw trip list regardless, ignoring
    // grouping entirely: printing "Vehicle Report" produced all 12 raw
    // trips instead of the 3-vehicle summary actually on screen. rows()
    // already builds the correct shape (and now the correct order, see
    // sortRawRows()/sortGroupedRows() above) — Print/PDF just needed to
    // use it instead of its own hardcoded raw query.
    private function payload(Request $request, bool $isPdf): array
    {
        $filters = $this->filters($request);
        $totals = $this->totals($filters);
        $isGrouped = $filters['report_type'] !== 'cost_summary'
            && ! in_array($filters['report_type'], ['records', 'paid', 'unpaid', 'outstanding'], true);

        $rows = $this->rows($filters);
        if ($isGrouped) {
            foreach ($rows as $row) {
                $row->group_label = $this->groupLabel($filters['report_type'], $row);
            }
        }

        return [
            'isPdf' => $isPdf,
            'companyName' => 'RANS Company Limited',
            'reportTypeLabel' => ucwords(str_replace('_', ' ', $filters['report_type'])),
            'groupColumnLabel' => $this->groupColumnLabel($filters['report_type']),
            'filters' => $filters,
            'filterLabels' => $this->filterLabels($filters),
            'rows' => $rows,
            'isGrouped' => $isGrouped,
            'totals' => $totals,
            'loading' => $this->loadingSection($request, $filters),
            'generatedBy' => optional($request->user())->name ?? 'N/A',
            'generatedAt' => $generatedAt = now(),
            // Signatories: the person who generates the report signs as Prepared By (nothing is stored; inert unless a document setup exists)
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('transport_report', \App\Services\Signatories\Documents\TransportReportDocument::record($request->user(), $generatedAt)),
            'autoPrint' => ! $isPdf,
        ];
    }

    /** The chosen filters in words, for the printed header ("Vehicle: T 123 ABC - Fuso", "Payment status: Unpaid" ...). */
    private function filterLabels(array $filters): array
    {
        $vehicle = ! empty($filters['asset_id']) ? \App\Models\Asset::find($filters['asset_id']) : null;

        return array_filter([
            'Vehicle' => $vehicle ? trim(($vehicle->registration_number ? $vehicle->registration_number . ' - ' : '') . $vehicle->name) : null,
            'Driver' => ! empty($filters['driver_contact_id']) ? Contact::find($filters['driver_contact_id'])?->name : null,
            'Project / Warehouse' => ! empty($filters['warehouse_id']) ? Warehouse::withoutGlobalScopes()->find($filters['warehouse_id'])?->name : null,
            'Destination type' => $filters['destination_type'] ?? null,
            'Payment status' => $filters['payment_status'] ?? null,
        ]);
    }

    private function groupColumnLabel(string $reportType): string
    {
        return match ($reportType) {
            'vehicle', 'vehicle_activity' => 'Vehicle',
            'driver' => 'Driver',
            'project', 'warehouse' => 'Project / Warehouse',
            'destination' => 'Destination',
            'daily' => 'Date',
            'monthly' => 'Month',
            default => 'Trip #',
        };
    }

    // Mirrors the Vue page's own groupLabel() exactly so the printed group
    // name matches what the user sees on screen.
    private function groupLabel(string $reportType, $row): string
    {
        return match ($reportType) {
            'vehicle', 'vehicle_activity' => $row->asset
                ? trim(($row->asset->registration_number ? $row->asset->registration_number . ' - ' : '') . $row->asset->name)
                : '—',
            'driver' => $row->driver?->name ?? '—',
            'project', 'warehouse' => $row->warehouse?->name ?? 'Company-wide',
            'destination' => $row->destination_label . ' (' . $row->destination_type . ')',
            'daily' => \Carbon\Carbon::parse($row->trip_day)->format('d M Y'),
            'monthly' => $row->trip_month,
            default => '—',
        };
    }
}
