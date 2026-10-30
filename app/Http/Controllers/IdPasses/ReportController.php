<?php

namespace App\Http\Controllers\IdPasses;

use App\Http\Controllers\Controller;
use App\Http\Controllers\IdPasses\Concerns\Guards;
use App\Models\IdPassCategory;
use App\Services\IdPass\IdPassKinds;
use App\Services\IdPass\IdPassReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The RANS ID reports: pick a report, filter it, read it on screen, print it, download it as PDF or CSV. Only the kinds the person may read are in it. */
class ReportController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function guard(): array
    {
        $this->module();
        $this->feature('id_reports');
        $this->need('read-id-reports');
        $kinds = IdPassKinds::readable();
        abort_if(! $kinds, 403);

        return $kinds;
    }

    private function filters(Request $request): array
    {
        $date = fn ($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($k)) ? (string) $request->query($k) : '';

        return ['type' => array_key_exists((string) $request->query('type'), IdPassReports::types()) ? (string) $request->query('type') : 'staff_register', 'q' => trim((string) $request->query('q')),
            'category_id' => ctype_digit((string) $request->query('category_id')) ? (int) $request->query('category_id') : 0, 'status' => (string) $request->query('status'), 'from' => $date('from'), 'to' => $date('to')];
    }

    public function index(Request $request)
    {
        $kinds = $this->guard();
        $f = $this->filters($request);
        $r = IdPassReports::build($f['type'], $f, $kinds, 'screen');
        $types = collect(IdPassReports::types())->map(fn ($t, $k) => ['value' => $k, 'label' => $t['title']])->values();

        return Inertia::render('IdPasses/Reports', ['filters' => $f, 'types' => $types, 'report' => ['title' => $r['title'], 'subtitle' => $r['subtitle'], 'tiles' => $r['tiles'], 'columns' => $r['columns'], 'rows' => array_slice($r['rows'], 0, 300), 'total' => $r['total_rows'], 'truncated' => $r['truncated'], 'sections' => $r['sections'], 'notes' => $r['notes']],
            'categories' => IdPassCategory::query()->whereIn('kind', $kinds)->orderBy('sort_order')->get()->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->values(), 'can' => ['print' => true]]);
    }

    private function report(Request $request, string $format): array
    {
        $kinds = $this->guard();
        $f = $this->filters($request);

        return IdPassReports::build($f['type'], $f, $kinds, $format);
    }

    private function pdfOf(array $r)
    {
        $pdf = Pdf::loadView('signatories.report-print', ['report' => $r, 'landscape' => true, 'isPdf' => true, 'autoPrint' => false, 'signatures' => ['enabled' => false],
            'companyName' => preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional(auth()->user()->account)->name), 'printedBy' => auth()->user()->name, 'printedAt' => now()->format('d M Y, H:i')])->setPaper('a4', 'landscape');
        $pdf->render();
        $d = $pdf->getDomPDF();
        $c = $d->getCanvas();
        $c->page_text($c->get_width() - 95, $c->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $d->getFontMetrics()->getFont('Helvetica'), 8.5, [0.61, 0.64, 0.69]);

        return $pdf;
    }

    public function print(Request $request)
    {
        $r = $this->report($request, 'print');

        return $this->pdfOf($r)->stream('rans-id-report-' . now()->format('Ymd-His') . '.pdf');
    }

    public function pdf(Request $request)
    {
        $this->feature('id_pdf_export');
        $r = $this->report($request, 'pdf');

        return $this->pdfOf($r)->download('rans-id-report-' . now()->format('Ymd-His') . '.pdf');
    }

    public function csv(Request $request)
    {
        $r = $this->report($request, 'csv');
        $safe = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;

        return response()->streamDownload(function () use ($r, $safe) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, array_column($r['columns'], 'label'));
            foreach ($r['raw'] as $row) {
                fputcsv($o, array_map(fn ($c) => $safe($row[$c['key']] ?? ''), $r['columns']));
            }
            fclose($o);
        }, 'rans-id-report-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
