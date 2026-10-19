<?php

namespace App\Http\Controllers\Signatories;

use App\Services\Signatories\SignatoryReportBuilder;
use App\Services\SmartBrief\Period;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/** Signatories reports: page with a live preview, and print / PDF / CSV. Exports need the export ability and every one is logged. */
class SignatoryReportController extends SignatoryBaseController
{
    public function __construct(private SignatoryReportBuilder $builder)
    {
        parent::__construct();
    }

    public function index(Request $request)
    {
        $user = $this->allow($request, 'view-signatories', 'signatories_reports');
        if ($request->boolean('preview')) {
            return response()->json($this->make($request, $user));
        }

        return Inertia::render('Signatories/Reports', [
            'types' => SignatoryReportBuilder::availability($user),
            'periods' => collect(Period::KEYS)->map(fn ($k) => ['key' => $k, 'label' => $k === 'custom' ? __('Custom range') : Period::resolve($k)->label])->values(),
            'filters' => ['type' => (string) $request->query('type', 'registered'), 'period' => (string) $request->query('period', 'last_30_days'), 'from' => $request->query('from'), 'to' => $request->query('to')],
            'can' => ['export' => $user->can('export-signatory-reports')],
        ]);
    }

    public function print(Request $request)
    {
        [$report, $user] = $this->exportable($request, 'print');

        return view('signatories.report-print', ['report' => $report, 'landscape' => SignatoryReportBuilder::landscape($report), 'isPdf' => false, 'autoPrint' => true, 'companyName' => $this->company($user), 'printedBy' => $user->name, 'printedAt' => now()->format('d M Y, H:i')]);
    }

    public function pdf(Request $request)
    {
        [$report, $user] = $this->exportable($request, 'pdf');
        $pdf = Pdf::loadView('signatories.report-print', ['report' => $report, 'landscape' => SignatoryReportBuilder::landscape($report), 'isPdf' => true, 'autoPrint' => false, 'companyName' => $this->company($user), 'printedBy' => $user->name, 'printedAt' => now()->format('d M Y, H:i')])
            ->setPaper('a4', SignatoryReportBuilder::landscape($report) ? 'landscape' : 'portrait');
        $pdf->render();
        $d = $pdf->getDomPDF();
        $c = $d->getCanvas();
        $c->page_text($c->get_width() - 95, $c->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $d->getFontMetrics()->getFont('Helvetica'), 8.5, [0.61, 0.64, 0.69]);

        return $pdf->download('signatories-' . $report['type'] . '-' . now()->format('Ymd-His') . '.pdf');
    }

    public function csv(Request $request)
    {
        [$report] = $this->exportable($request, 'csv');
        $labels = array_column($report['columns'], 'label');
        $keys = array_column($report['columns'], 'key');
        $safe = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
        $file = 'signatories-' . $report['type'] . '-' . now()->format('Ymd-His') . '.csv';
        if ($report['rows'] === []) {
            return response()->streamDownload(function () use ($labels) {
                $o = fopen('php://output', 'w');
                fputcsv($o, $labels);
                fclose($o);
            }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $gen = function () use ($report, $keys, $labels, $safe) {
            foreach ($report['rows'] as $row) {
                $o = [];
                foreach ($keys as $i => $k) {
                    $o[$labels[$i]] = $safe($row[$k] ?? '');
                }
                yield $o;
            }
        };
        $resp = (new FastExcel($gen()))->download($file);
        $resp->headers->set('Content-Type', 'text/csv; charset=UTF-8');

        return $resp;
    }

    private function make(Request $request, $user): array
    {
        $type = (string) $request->query('type', '');
        abort_unless(SignatoryReportBuilder::exists($type) && $user->can(SignatoryReportBuilder::TYPES[$type]['ability']), $type && SignatoryReportBuilder::exists($type) ? 403 : 404);
        try {
            $period = Period::resolve($request->query('period', 'last_30_days'), $request->query('from'), $request->query('to'), 366);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $this->builder->build($user, $type, $period);
    }

    private function exportable(Request $request, string $format): array
    {
        $user = $this->allow($request, 'export-signatory-reports', 'signatories_reports');
        $report = $this->make($request, $user);
        log_activity('Signatories report exported', ['type' => $report['type'], 'format' => $format, 'rows' => $report['total_rows'], 'reference' => $report['reference']], null, 'Signatories');

        return [$report, $user];
    }

    private function company($user): string
    {
        return preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional($user->account)->name);
    }
}
