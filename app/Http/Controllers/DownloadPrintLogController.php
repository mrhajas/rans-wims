<?php

namespace App\Http\Controllers;

use App\Models\DownloadPrintLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DownloadPrintLogController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', DownloadPrintLog::class);

        $filters = $request->all(
            'search',
            'action_type',
            'module',
            'start_date',
            'end_date',
            'sort',
            'sort_direction'
        );

        $query = DownloadPrintLog::query()
            ->with('user:id,name,username')
            ->filter($filters);

        // Counts ignore the `action_type` filter itself (but honor every
        // other active filter) so the two stat cards always show the true
        // split to click between — see LoginLogController::index() for the
        // same reasoning.
        $countFilters = collect($filters)->except('action_type')->all();
        $countBase = DownloadPrintLog::query()->filter($countFilters);

        return Inertia::render('DownloadPrintLog/Index', [
            'filters' => $filters,
            'logs' => $query->paginate()->withQueryString(),
            'modules' => DownloadPrintLog::query()
                ->whereNotNull('module')
                ->select('module')
                ->distinct()
                ->orderBy('module')
                ->pluck('module')
                ->values(),
            'stats' => [
                'downloads' => (clone $countBase)->where('action_type', 'download')->count(),
                'prints' => (clone $countBase)->where('action_type', 'print')->count(),
            ],
        ]);
    }

    public function print(Request $request)
    {
        $this->authorize('viewAny', DownloadPrintLog::class);

        return view('download-print-logs-print', $this->viewData($request, true));
    }

    public function pdf(Request $request)
    {
        $this->authorize('viewAny', DownloadPrintLog::class);

        ini_set('memory_limit', '1024M'); // the log table can be rendered into one wide PDF
        $pdf = Pdf::loadView('download-print-logs-print', $this->viewData($request, false))
            ->setPaper('a4', 'landscape');

        return $pdf->download('download-print-logs-' . now()->format('Ymd-His') . '.pdf');
    }

    private function viewData(Request $request, bool $autoPrint): array
    {
        $filters = $request->all(
            'search',
            'action_type',
            'module',
            'start_date',
            'end_date',
            'sort',
            'sort_direction'
        );

        $logs = DownloadPrintLog::query()
            ->with('user:id,name,username')
            ->filter($filters)
            ->limit(3000)
            ->get();

        return [
            'title' => 'Download / Print Logs',
            'logs' => $logs,
            'filters' => $filters,
            'autoPrint' => $autoPrint,
            'isPdf' => ! $autoPrint,
            'companyName' => optional($request->user()->account ?? null)->name,
            'generatedBy' => optional($request->user())->name,
            'generatedAt' => now(),
        ];
    }
}
