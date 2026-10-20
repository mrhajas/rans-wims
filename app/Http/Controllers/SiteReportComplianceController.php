<?php

namespace App\Http\Controllers;

use App\Models\DailySiteReport;
use App\Models\User;
use App\Services\SiteReportAssignments;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

class SiteReportComplianceController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->authorizeAccess($request);
        $matrix = $this->buildMatrix($request, $user);

        return Inertia::render('DailySiteReport/Compliance', $matrix);
    }

    public function print(Request $request)
    {
        $user = $this->authorizeAccess($request);
        $payload = $this->printPayload($request, $user);

        return view('daily-site-report-compliance-print', $payload);
    }

    public function pdf(Request $request)
    {
        $user = $this->authorizeAccess($request);
        $payload = $this->printPayload($request, $user);

        $pdf = Pdf::loadView('daily-site-report-compliance-print', $payload);

        return $pdf->download('site-report-compliance-' . now()->format('Ymd-His') . '.pdf');
    }

    public function export(Request $request)
    {
        $user = $this->authorizeAccess($request);
        $matrix = $this->buildMatrix($request, $user);

        $rows = collect($matrix['rows'])->map(function ($row) use ($matrix) {
            $flat = [
                'Site Engineer' => $row['user_name'],
                'Project' => $row['warehouse_name'],
            ];

            foreach ($matrix['dates'] as $date) {
                $flat[$date] = ($row['cells'][$date]['submitted'] ?? false) ? 'Yes' : 'No';
            }

            return $flat;
        });

        return (new FastExcel($rows))->download('site-report-compliance-' . now()->format('Ymd-His') . '.xlsx');
    }

    protected function authorizeAccess(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->hasRole(['Super Admin', 'Admin', 'Project Manager', 'Project Managers']), 403);

        return $user;
    }

    protected function printPayload(Request $request, User $user): array
    {
        $matrix = $this->buildMatrix($request, $user);

        $warehouseName = $matrix['filters']['warehouse_id']
            ? collect($matrix['warehouses'])->firstWhere('id', (int) $matrix['filters']['warehouse_id'])['name'] ?? null
            : null;

        $siteEngineerName = $matrix['filters']['submitted_by']
            ? collect($matrix['siteEngineers'])->firstWhere('id', (int) $matrix['filters']['submitted_by'])['name'] ?? null
            : null;

        return array_merge($matrix, [
            'filterWarehouseName' => $warehouseName,
            'filterSiteEngineerName' => $siteEngineerName,
            'logoPath' => $this->reportLogo(),
            'printedBy' => $user->name,
            'printedAt' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    protected function reportLogo(): ?string
    {
        $logo = public_path('images/logo.png');
        if (! file_exists($logo)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode(file_get_contents($logo));
    }

    protected function buildMatrix(Request $request, User $user): array
    {
        $view = $request->input('view') === 'month' ? 'month' : 'week';
        $anchor = $request->input('anchor') ? Carbon::parse($request->input('anchor')) : now();

        if ($view === 'month') {
            $start = $anchor->copy()->startOfMonth();
            $end = $anchor->copy()->endOfMonth();
        } else {
            $start = $anchor->copy()->startOfWeek();
            $end = $anchor->copy()->endOfWeek();
        }

        $dates = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $dates[] = $cursor->format('Y-m-d');
        }

        $allPairs = SiteReportAssignments::pairs($user);
        $pairs = $allPairs;

        if ($request->filled('warehouse_id')) {
            $pairs = $pairs->where('warehouse_id', (int) $request->input('warehouse_id'));
        }
        if ($request->filled('submitted_by')) {
            $pairs = $pairs->where('user_id', (int) $request->input('submitted_by'));
        }

        $userIds = $pairs->pluck('user_id')->unique()->values();
        $warehouseIds = $pairs->pluck('warehouse_id')->unique()->values();

        $reports = DailySiteReport::query()
            ->whereIn('submitted_by', $userIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereBetween('report_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get(['id', 'report_number', 'submitted_by', 'warehouse_id', 'report_date', 'created_at']);

        $reportsByKey = $reports->keyBy(
            fn ($r) => $r->submitted_by . '-' . $r->warehouse_id . '-' . $r->report_date->format('Y-m-d')
        );

        $rows = $pairs->map(function ($pair) use ($dates, $reportsByKey) {
            $cells = [];
            foreach ($dates as $date) {
                $report = $reportsByKey->get($pair->user_id . '-' . $pair->warehouse_id . '-' . $date);
                $cells[$date] = [
                    'submitted' => (bool) $report,
                    'report_id' => $report?->id,
                    'report_number' => $report?->report_number,
                    'schedule_status' => $report?->schedule_status,
                    'submitted_at' => $report?->submitted_at,
                ];
            }

            return [
                'user_id' => $pair->user_id,
                'user_name' => $pair->user_name,
                'warehouse_id' => $pair->warehouse_id,
                'warehouse_name' => $pair->warehouse_name,
                'cells' => $cells,
            ];
        })->values()->all();

        return [
            'view' => $view,
            'anchor' => $anchor->format('Y-m-d'),
            'dates' => $dates,
            'rows' => $rows,
            'warehouses' => $this->warehouseOptionsOf($allPairs),
            'siteEngineers' => $allPairs->pluck('user_name', 'user_id')
                ->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all(),
            'filters' => [
                'warehouse_id' => $request->input('warehouse_id'),
                'submitted_by' => $request->input('submitted_by'),
            ],
        ];
    }

    /** The projects of the matrix as dropdown options, with their category so the list can be shown in sections. */
    protected function warehouseOptionsOf($allPairs): array
    {
        $names = $allPairs->pluck('warehouse_name', 'warehouse_id');
        $status = \App\Models\Warehouse::query()->whereIn('id', $names->keys()->all() ?: [0])->pluck('status', 'id');

        return $names->map(fn ($name, $id) => ['id' => $id, 'name' => $name, 'status' => $status[$id] ?? null])->values()->all();
    }
}
