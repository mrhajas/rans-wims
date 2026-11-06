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

    /** What WIMS Logs has already moved out of this list: how many records, from when to when, how long events stay here, and whether this person may read them. */
    private function archivedInfo(Request $request): ?array
    {
        try {
            $a = \App\Models\WimsLogArchive::query()->where('source', 'downloads')->selectRaw('count(*) as archives, sum(rows_n) as rows_n, min(date_from) as date_from, max(date_to) as date_to')->first();
            if (! $a || ! (int) $a->rows_n) {
                return null;
            }

            return [
                'rows' => (int) $a->rows_n, 'archives' => (int) $a->archives, 'from' => $a->date_from, 'to' => $a->date_to,
                'keep_days' => \App\Support\WimsLogs\WimsLogSettings::keepDays('downloads'),
                'can_read' => (bool) $request->user()->can('read-wims-logs'),
            ];
        } catch (\Throwable $e) {
            return null; // the archive tables do not exist here (yet)
        }
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
            // older events are moved to compressed archives by Settings > WIMS Logs: said here so nobody looks for them in this list
            'archived' => fn () => $this->archivedInfo($request),
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
