<?php

namespace App\Http\Controllers\SmartBrief;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SmartBrief\Access;
use App\Services\SmartBrief\Period;
use App\Services\SmartBrief\ReportBuilder;
use App\Services\SmartBrief\Settings;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * Smart Brief reports: the page (with a live preview) and the print / PDF / CSV exports. All of them build the report through
 * ReportBuilder, which goes through the Smart Brief engine, so what is printed is never more than the viewer may see on screen.
 * The reports page needs "view-smart-brief-reports"; an export needs the Export function, and "export-smart-brief" whenever the report
 * holds anybody's activity but the viewer's own brief. Every export is written to the activity log (log name "Smart Brief").
 */
class SmartBriefReportController extends Controller
{
    public function __construct(private ReportBuilder $builder)
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('view-smart-brief-reports') && Access::feature('smart_brief_reports', $user), 403);

        if ($request->boolean('preview')) {
            return response()->json($this->preview($request, $user));
        }

        $settings = Settings::get($user->account_id);
        $types = array_values(ReportBuilder::availability($user));
        $filters = $this->filters($request);
        $management = Access::canOpenManagement($user);
        $selected = null;
        if (! empty($filters['user_id'])) {
            $u = User::query()->where('account_id', $user->account_id)->find((int) $filters['user_id']);
            if ($u && Access::canViewSubject($user, $u)) {
                $selected = ['id' => $u->id, 'name' => $u->name, 'username' => $u->username];
            }
        }
        $export = Access::feature('smart_brief_export', $user);

        return Inertia::render('SmartBrief/Reports', [
            'types' => $types,
            'filters' => [
                'type' => $filters['type'] ?: (collect($types)->firstWhere('allowed', true)['key'] ?? 'individual_daily'),
                'period' => $request->query('period', $settings['default_period']),
                'from' => $request->query('from'), 'to' => $request->query('to'),
                'user_id' => $filters['user_id'] ?: null, 'role' => $filters['role'] ?: null, 'project' => $filters['project'] ?: null,
            ],
            'selectedUser' => $selected,
            'me' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'own' => Access::canViewOwn($user)],
            'periods' => collect(Period::KEYS)->map(fn ($k) => ['key' => $k, 'label' => $k === 'custom' ? __('Custom range') : Period::resolve($k)->label])->values()->all(),
            'prefs' => ['default_period' => $settings['default_period'], 'max_range_days' => (int) $settings['max_range_days']],
            'lookups' => $management ? [
                'people' => app(\App\Services\SmartBrief\Overview::class)->subjects($user, ['limit' => 500])->map(fn ($u) => [
                    'value' => $u->id, 'label' => $u->name . ($u->roles->isNotEmpty() ? ' (' . $u->roles->pluck('name')->implode(', ') . ')' : ''), 'name' => $u->name, 'username' => $u->username,
                ])->values()->all(),
                'roles' => \App\Models\Role::ofAccount()->orderBy('name')->pluck('name'),
                'projects' => Warehouse::query()->orderBy('name')->get(['id', 'name']),
            ] : ['people' => [], 'roles' => [], 'projects' => []],
            'can' => [
                'management' => $management,
                'export_own' => $export,
                'export_all' => $export && $user->can('export-smart-brief'),
            ],
        ]);
    }

    public function print(Request $request)
    {
        [$report, $viewer] = $this->exportReport($request, 'print');

        return view('smart-brief.report-print', $this->viewData($report, $viewer, false, true));
    }

    public function pdf(Request $request)
    {
        // inline=1 is the Print button: the same PDF, opened in the browser's viewer (to print from) instead of downloaded, so paper and file look identical
        $inline = $request->boolean('inline');
        [$report, $viewer] = $this->exportReport($request, $inline ? 'print' : 'pdf');

        $pdf = Pdf::loadView('smart-brief.report-print', $this->viewData($report, $viewer, true, false))
            ->setPaper('a4', ReportBuilder::landscape($report) ? 'landscape' : 'portrait');
        // "Page X of Y": DomPDF 3 has no CSS counters, so the canvas is stamped after render() (same approach as the project certificate)
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica');
        $canvas->page_text($canvas->get_width() - 95, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8.5, [0.61, 0.64, 0.69]);

        return $inline ? $pdf->stream($this->fileName($report, 'pdf')) : $pdf->download($this->fileName($report, 'pdf'));
    }

    public function csv(Request $request)
    {
        [$report] = $this->exportReport($request, 'csv');
        $file = $this->fileName($report, 'csv');
        $labels = array_column($report['columns'], 'label');
        $keys = array_column($report['columns'], 'key');

        if ($report['rows'] === []) {
            // FastExcel writes nothing for an empty list: still give the reader the header row
            return response()->streamDownload(function () use ($labels) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $labels);
                fclose($out);
            }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $rows = function () use ($report, $keys, $labels) {
            foreach ($report['rows'] as $row) {
                $out = [];
                foreach ($keys as $i => $k) {
                    $out[$labels[$i]] = $this->csvSafe($row[$k] ?? '');
                }
                yield $out;
            }
        };

        $response = (new FastExcel($rows()))->download($file);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');

        return $response;
    }

    // ------------------------------------------------------------------------------------------------------------------------------ helpers

    /** The report as JSON for the live preview. Previews of anybody else's activity are logged too. */
    private function preview(Request $request, User $viewer): array
    {
        $type = $this->typeOf($request);
        $filters = $this->filters($request);
        $period = $this->period($request, $viewer);
        $report = $this->builder->build($viewer, $type, $period, $filters);
        $this->audit($viewer, $report, $filters, 'previewed', null);
        $own = ReportBuilder::isOwnBrief($viewer, $type, $filters);
        $report['export'] = [
            'allowed' => Access::feature('smart_brief_export', $viewer) && ($own || $viewer->can('export-smart-brief')),
            'landscape' => ReportBuilder::landscape($report),
        ];

        return $report;
    }

    /** Authorise and build a report for print / pdf / csv, and write the audit row. */
    private function exportReport(Request $request, string $format): array
    {
        $viewer = $request->user();
        abort_unless(Access::feature('smart_brief_export', $viewer), 403, __('Smart Brief export is switched off.'));
        $type = $this->typeOf($request);
        $filters = $this->filters($request);
        abort_unless(ReportBuilder::isOwnBrief($viewer, $type, $filters) || $viewer->can('export-smart-brief'), 403, __('You are not allowed to export Smart Brief reports that contain other people\'s activity.'));

        $report = $this->builder->build($viewer, $type, $this->period($request, $viewer), $filters);
        $this->audit($viewer, $report, $filters, 'exported', $format);

        return [$report, $viewer];
    }

    private function audit(User $viewer, array $report, array $filters, string $action, ?string $format): void
    {
        $subjectId = $report['subject']['id'] ?? null;
        if ($action === 'previewed' && $subjectId && (int) $subjectId === (int) $viewer->id) {
            return; // looking at your own brief is not logged
        }
        $details = array_filter([
            'type' => $report['type'], 'format' => $format, 'period' => $report['period']['label'], 'from' => $report['period']['from'], 'to' => $report['period']['to'],
            'subject_user_id' => $subjectId, 'filters' => array_filter($filters), 'rows' => $report['total_rows'], 'reference' => $report['reference'],
        ], fn ($v) => $v !== null && $v !== []);

        if ($subjectId && (int) $subjectId !== (int) $viewer->id) {
            Access::audit($viewer, User::query()->findOrFail($subjectId), 'report ' . $action, $details);
            if ($action === 'exported') {
                $this->tell($viewer, $report, $format, User::query()->find($subjectId));
            }

            return;
        }
        if ($action === 'exported' && ! $subjectId) {
            $this->tell($viewer, $report, $format, null);
        }
        // log_activity() cannot take a null model, so this goes straight to the logger (same log name as Access::audit)
        activity('Smart Brief')->withProperties($details + ['viewer_user_id' => $viewer->id, 'viewer' => $viewer->name])->log('Smart Brief report ' . $action);
    }

    private function tell(User $viewer, array $report, ?string $format, ?User $subject): void
    {
        \App\Services\SmartBrief\Alerts::fire('report_exported', $viewer, null, __('Smart Brief report exported'),
            __(':v exported the Smart Brief report ":r" (:p) as :f.', ['v' => $viewer->name, 'r' => $report['title'], 'p' => $report['period']['label'], 'f' => strtoupper((string) $format)]),
            ['report' => $report['title'], 'period' => $report['period']['label'], 'format' => strtoupper((string) $format)]);
    }

    private function viewData(array $report, User $viewer, bool $isPdf, bool $autoPrint): array
    {
        $qr = null;
        if (! empty($report['qr_url'])) {
            try {
                $qr = PrintQrCode::dataUri($report['qr_url']);
            } catch (\Throwable) {
                $qr = null;
            }
        }

        return [
            'report' => $report, 'isPdf' => $isPdf, 'autoPrint' => $autoPrint, 'landscape' => ReportBuilder::landscape($report), 'qr' => $qr,
            'companyName' => preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional($viewer->account)->name),
            'printedBy' => $viewer->name, 'printedAt' => now()->format('d M Y, H:i'),
        ];
    }

    private function fileName(array $report, string $ext): string
    {
        return 'smart-brief-' . str_replace('_', '-', $report['type']) . '-' . now()->format('Ymd-His') . '.' . $ext;
    }

    /** Spreadsheet programs run text that starts with = + - @ as a formula: neutralise it. */
    private function csvSafe($v)
    {
        if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            return "'" . $v;
        }

        return $v;
    }

    private function typeOf(Request $request): string
    {
        $type = (string) $request->query('type', '');
        abort_unless(ReportBuilder::exists($type), 404, __('Unknown report type.'));

        return $type;
    }

    private function filters(Request $request): array
    {
        return [
            'type' => (string) $request->query('type', ''),
            'user_id' => ctype_digit((string) $request->query('user_id', '')) ? (int) $request->query('user_id') : 0,
            'role' => trim((string) $request->query('role', '')),
            'project' => ctype_digit((string) $request->query('project', '')) ? (int) $request->query('project') : 0,
        ];
    }

    private function period(Request $request, User $viewer): Period
    {
        $settings = Settings::get($viewer->account_id);
        try {
            return Period::resolve($request->query('period', $settings['default_period']), $request->query('from'), $request->query('to'), (int) $settings['max_range_days']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }
}
