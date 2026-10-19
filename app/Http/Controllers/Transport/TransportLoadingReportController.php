<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Warehouse;
use App\Services\Transport\LoadingLedger;
use App\Services\Transport\LoadingReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * Transport Routes > Loading & Unloading Report: one report with filters (period, personnel, project / site, status,
 * custom rates, owing only) in three shapes (summary, attendance, payments). Page, print, PDF and Excel all come from
 * App\Services\Transport\LoadingReport so they always agree. Print / PDF / Excel hits are written to the Download / Print log
 * by LogDownloadPrintActions (the route names contain print / pdf / export) and, with the filters used, to the activity log.
 */
class TransportLoadingReportController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-transport-loading-reports');

        $filters = LoadingReport::filters($request);
        $user = $request->user();

        return Inertia::render('TransportManagement/Loading/Report', LoadingReport::build($filters) + [
            'workers' => Contact::query()->whereIn('id', LoadingLedger::workerIds() ?: [0])->orderBy('name')->get(['id', 'name']),
            'projects' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name']),
            'places' => \App\Models\TransportLoadingPlace::names(),
            'can' => [
                'print' => $user->can('print-transport-loading-reports'),
                'export' => $user->can('export-transport-loading-reports'),
            ],
        ]);
    }

    public function printList(Request $request)
    {
        $this->authorize('print-transport-loading-reports');

        return view('reports.transport-loading', $this->payload($request, false, 'printed'));
    }

    public function pdfList(Request $request)
    {
        $this->authorize('print-transport-loading-reports');

        @ini_set('memory_limit', '512M');
        $data = $this->payload($request, true, 'downloaded as PDF');

        return Pdf::loadView('reports.transport-loading', $data)
            ->setPaper('a4', $data['report']['filters']['report_type'] === 'summary' ? 'portrait' : 'landscape')
            ->download('loading-unloading-report-' . $data['report']['filters']['report_type'] . '-' . now()->format('Ymd-His') . '.pdf');
    }

    public function export(Request $request)
    {
        $this->authorize('export-transport-loading-reports');

        $report = LoadingReport::build(LoadingReport::filters($request));
        $this->audit($request, $report, 'exported to Excel');

        return (new FastExcel(collect(LoadingReport::excelRows($report))))->download('loading-unloading-report-' . $report['filters']['report_type'] . '-' . now()->format('Ymd-His') . '.xlsx');
    }

    private function payload(Request $request, bool $isPdf, string $action): array
    {
        $report = LoadingReport::build(LoadingReport::filters($request));
        $this->audit($request, $report, $action);
        $f = $report['filters'];

        return [
            'isPdf' => $isPdf,
            'companyName' => 'RANS Company Limited',
            'report' => $report,
            'contactName' => $f['contact_id'] ? Contact::whereKey($f['contact_id'])->value('name') : null,
            'projectName' => $f['warehouse_id'] ? Warehouse::whereKey($f['warehouse_id'])->value('name') : ($f['place'] ?? null),
            'generatedBy' => optional($request->user())->name ?? 'N/A',
            'generatedAt' => $generatedAt = now(),
            // Signatories: the person who generates the report signs as Prepared By (nothing is stored; inert unless a document setup exists)
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('transport_loading_sheet', \App\Services\Signatories\Documents\TransportLoadingSheetDocument::record($request->user(), $generatedAt)),
            'autoPrint' => ! $isPdf,
        ];
    }

    private function audit(Request $request, array $report, string $action): void
    {
        log_activity(__('Loading & Unloading report :action.', ['action' => $action]), ['filters' => $report['filters'], 'rows' => count($report['rows'])], $request->user(), 'Transport Loading');
    }
}
