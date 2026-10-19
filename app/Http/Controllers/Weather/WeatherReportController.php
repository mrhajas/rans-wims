<?php

namespace App\Http\Controllers\Weather;

use App\Models\User;
use App\Services\Weather\WeatherAccess;
use App\Services\Weather\WeatherReportBuilder;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * Weather & Site Risk report: page with a live preview (read-weather) and print / PDF / CSV (print-weather-reports). One builder feeds all of
 * them. Every export is written to the activity log.
 */
class WeatherReportController extends WeatherBaseController
{
    private const MAX_DAYS = 366;

    public function __construct(private WeatherReportBuilder $builder)
    {
        parent::__construct();
    }

    public function index(Request $request)
    {
        $user = $this->allow($request, 'read-weather', 'weather_reports');
        $filters = $this->filters($request, $user);
        if ($request->boolean('preview')) {
            $report = $this->builder->build($user, $filters);
            unset($report['datasets']);

            return response()->json($report);
        }

        return Inertia::render('Weather/Reports', [
            'filters' => ['project' => $filters['project'] ? (string) $filters['project'] : '', 'from' => $filters['from'], 'to' => $filters['to']],
            'projectOptions' => $this->projectOptions($user, false),
            'can' => ['print' => $user->can('print-weather-reports')],
            'maxDays' => self::MAX_DAYS,
            'sections' => [['value' => 'observations', 'label' => __('Observations')], ['value' => 'warnings', 'label' => __('Warnings and acknowledgements')], ['value' => 'comparison', 'label' => __('Forecast against observed')], ['value' => 'sources', 'label' => __('Forecast sources')]],
        ]);
    }

    public function print(Request $request)
    {
        [$report, $user] = $this->exportReport($request, 'print');

        return view('weather.report-print', $this->viewData($report, $user, false, true));
    }

    public function pdf(Request $request)
    {
        [$report, $user] = $this->exportReport($request, 'pdf');
        $pdf = Pdf::loadView('weather.report-print', $this->viewData($report, $user, true, false))->setPaper('a4', 'landscape');
        // "Page X of Y": DomPDF has no CSS counters, so the canvas is stamped after render()
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica');
        $canvas->page_text($canvas->get_width() - 95, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8.5, [0.61, 0.64, 0.69]);

        return $pdf->download('weather-site-risk-' . now()->format('Ymd-His') . '.pdf');
    }

    public function csv(Request $request)
    {
        [$report] = $this->exportReport($request, 'csv');
        $section = in_array($request->query('section'), ['observations', 'warnings', 'comparison', 'sources'], true) ? $request->query('section') : 'observations';
        $set = $report['datasets'][$section];
        $file = 'weather-site-risk-' . $section . '-' . now()->format('Ymd-His') . '.csv';
        $labels = array_column($set['columns'], 'label');
        $keys = array_column($set['columns'], 'key');
        if ($set['rows'] === []) {
            return response()->streamDownload(function () use ($labels) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $labels);
                fclose($out);
            }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $rows = function () use ($set, $keys, $labels) {
            foreach ($set['rows'] as $row) {
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

    private function exportReport(Request $request, string $format): array
    {
        $user = $this->allow($request, 'print-weather-reports', 'weather_reports');
        $filters = $this->filters($request, $user);
        $report = $this->builder->build($user, $filters);
        log_activity('Weather report exported', ['format' => $format, 'reference' => $report['reference'], 'project_id' => $filters['project'], 'from' => $filters['from'], 'to' => $filters['to'],
            'observations' => $report['total_rows'], 'section' => $format === 'csv' ? $request->query('section', 'observations') : null], null, 'Weather');

        return [$report, $user];
    }

    private function viewData(array $report, User $user, bool $isPdf, bool $autoPrint): array
    {
        $qr = null;
        try {
            $qr = PrintQrCode::dataUri($report['qr_url']);
        } catch (\Throwable) {
            $qr = null;
        }

        return [
            'report' => $report, 'isPdf' => $isPdf, 'autoPrint' => $autoPrint, 'landscape' => true, 'qr' => $qr,
            'companyName' => preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional($user->account)->name),
            'printedBy' => $user->name, 'printedAt' => ($generatedAt = now())->format('d M Y, H:i'),
            // Signatories: the person who generates the report signs as Prepared By (nothing is stored; inert unless a document setup exists)
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('weather_report', \App\Services\Signatories\Documents\WeatherReportDocument::record($user, $generatedAt, $report['reference'] ?? null), $user),
        ];
    }

    private function csvSafe($v)
    {
        if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            return "'" . $v;
        }

        return $v;
    }

    private function filters(Request $request, User $user): array
    {
        $project = ctype_digit((string) $request->query('project', '')) ? (int) $request->query('project') : 0;
        if ($project) {
            $this->project($user, $project); // 404 for a project the person may not see
        }
        $ok = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
        $to = $ok($request->query('to')) ? $request->query('to') : now()->toDateString();
        $from = $ok($request->query('from')) ? $request->query('from') : CarbonImmutable::parse($to)->subDays(29)->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_DAYS) {
            abort(422, __('Choose a period of at most :d days.', ['d' => self::MAX_DAYS]));
        }

        return ['project' => $project ?: null, 'from' => $from, 'to' => $to];
    }
}
