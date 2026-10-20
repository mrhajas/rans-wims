<?php

namespace App\Http\Controllers\ExternalStock;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ExternalStockMovement;
use App\Models\Warehouse;
use App\Services\ExternalStock\ExternalStockReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/** External Stock reports: the same WIMS report layout as every other report; print, PDF, Excel and CSV; every export is logged. */
class ReportController extends Controller
{
    private const KEYS = ['contact_id', 'warehouse_id', 'item', 'kind', 'status', 'from', 'to', 'as_of', 'only_stock'];

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-external-stock'), 403);
        $type = (string) $request->query('type', 'balances');
        $filters = $request->only(self::KEYS);
        if ($request->boolean('preview') && ExternalStockReports::exists($type)) {
            return response()->json(collect(ExternalStockReports::build($request->user(), $type, $filters, 300))->except('raw')->all());
        }
        $ids = accessible_warehouse_ids();

        return Inertia::render('ExternalStock/Reports', [
            'catalog' => ExternalStockReports::catalog(), 'type' => ExternalStockReports::exists($type) ? $type : 'balances', 'filters' => $filters,
            'people' => Contact::query()->where('category', Contact::EXTERNAL_CATEGORY)->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name]),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name, 'status' => $w->status]),
            'kinds' => collect(ExternalStockMovement::KINDS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'statuses' => collect(ExternalStockMovement::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(),
            'can' => ['print' => $request->user()->can('print-external-stock')],
        ]);
    }

    public function print(Request $request)
    {
        return $this->pdfOf($this->exportable($request, 'print'))->stream($this->name($request));
    }

    public function pdf(Request $request)
    {
        return $this->pdfOf($this->exportable($request, 'pdf'))->download($this->name($request));
    }

    public function csv(Request $request)
    {
        return $this->sheet($request, 'csv');
    }

    public function xlsx(Request $request)
    {
        return $this->sheet($request, 'xlsx');
    }

    private function name(Request $request): string
    {
        return 'external-stock-' . preg_replace('/[^a-z_]/', '', (string) $request->query('type', 'balances')) . '-' . now()->format('Ymd-His') . '.pdf';
    }

    private function exportable(Request $request, string $format): array
    {
        abort_unless($request->user()->can('read-external-stock') && $request->user()->can('print-external-stock'), 403);
        $type = (string) $request->query('type', 'balances');
        abort_unless(ExternalStockReports::exists($type), 404);
        $r = ExternalStockReports::build($request->user(), $type, $request->only(self::KEYS), $format === 'pdf' || $format === 'print' ? 5000 : 50000);
        log_activity('External Stock report exported', ['type' => $type, 'format' => $format, 'rows' => $r['total_rows'], 'reference' => $r['reference'], 'filters' => $r['filters']], null, 'External Stock');

        return $r;
    }

    private function pdfOf(array $r)
    {
        $landscape = count($r['columns']) > 6;
        $pdf = Pdf::loadView('signatories.report-print', ['report' => $r, 'landscape' => $landscape, 'isPdf' => true, 'autoPrint' => false, 'companyName' => preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional(auth()->user()->account)->name), 'printedBy' => auth()->user()->name, 'printedAt' => now()->format('d M Y, H:i')])
            ->setPaper('a4', $landscape ? 'landscape' : 'portrait');
        $pdf->render();
        $d = $pdf->getDomPDF();
        $c = $d->getCanvas();
        $c->page_text($c->get_width() - 95, $c->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $d->getFontMetrics()->getFont('Helvetica'), 8.5, [0.61, 0.64, 0.69]);

        return $pdf;
    }

    private function sheet(Request $request, string $ext)
    {
        $r = $this->exportable($request, $ext);
        $file = 'external-stock-' . $r['type'] . '-' . now()->format('Ymd-His') . '.' . $ext;
        $safe = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
        if ($r['raw'] === []) {
            return response()->streamDownload(function () use ($r) {
                $o = fopen('php://output', 'w');
                fputcsv($o, array_column($r['columns'], 'label'));
                fclose($o);
            }, preg_replace('/\.xlsx$/', '.csv', $file), ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $gen = function () use ($r, $safe) {
            foreach ($r['raw'] as $row) {
                $o = [];
                foreach ($r['columns'] as $c) {
                    $o[$c['label']] = $safe($row[$c['key']] ?? '');
                }
                yield $o;
            }
        };
        $resp = (new FastExcel($gen()))->download($file);
        if ($ext === 'csv') {
            $resp->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        }

        return $resp;
    }
}
