<?php

namespace App\Http\Controllers\VisitorLogs;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use App\Models\VisitorOption;
use App\Models\VisitorVisit;
use App\Services\VisitorLogs\VisitorReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/** Visitor Logs reports: the same WIMS report layout as every other report; print, PDF, Excel and CSV; every export is logged. */
class ReportController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function allowed(Request $request, string $type): void
    {
        $u = $request->user();
        abort_unless($u->can('read-visitor-reports'), 403);
        $c = collect(VisitorReports::catalog())->firstWhere('key', $type);
        abort_unless($c && collect($c['needs'])->every(fn ($a) => $u->can($a)), 403);
    }

    public function index(Request $request)
    {
        $me = $request->user();
        abort_unless($me->can('read-visitor-reports'), 403);
        $catalog = VisitorReports::allowedFor($me);
        abort_if($catalog === [], 403);
        $type = (string) $request->query('type', $catalog[0]['key']);
        if (VisitorReports::exists($type) && ! in_array($type, array_column($catalog, 'key'), true)) {
            abort(403); // a report the person asked for by name but may not open: refused, never swapped for another one
        }
        $type = in_array($type, array_column($catalog, 'key'), true) ? $type : $catalog[0]['key'];
        $filters = $request->only(VisitorReports::keys());
        if ($request->boolean('preview')) {
            $this->allowed($request, $type);

            return response()->json(collect(VisitorReports::build($me, $type, $filters, 300))->except('raw')->all());
        }

        return Inertia::render('VisitorLogs/Reports', [
            'catalog' => $catalog, 'type' => $type, 'filters' => $filters, 'statuses' => VisitorVisit::STATUSES, 'itemStatuses' => VisitorOption::names('item_status', false), 'itemTypes' => VisitorOption::names('item_type', false),
            'warehouses' => \App\Services\VisitorLogs\VisitorSettings::warehouseChoices($me), 'purposes' => VisitorOption::names('purpose', false), 'departments' => VisitorOption::names('department', false), 'can' => ['print' => $me->can('print-visitor-reports'), 'history' => $me->can('view-visitor-history')],
            'hosts' => \App\Models\User::query()->whereIn('id', VisitorVisit::query()->visibleTo($me)->whereNotNull('host_user_id')->distinct()->pluck('host_user_id'))->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name]),
            'visitors' => $me->can('view-visitor-history') ? Visitor::query()->orderBy('full_name')->limit(500)->get(['id', 'full_name', 'company'])->map(fn ($v) => ['value' => (string) $v->id, 'label' => $v->full_name . ($v->company ? ' · ' . $v->company : '')]) : [],
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
        return 'visitor-logs-' . preg_replace('/[^a-z_]/', '', (string) $request->query('type', 'daily_register')) . '-' . now()->format('Ymd-His') . '.pdf';
    }

    private function exportable(Request $request, string $format): array
    {
        $type = (string) $request->query('type', 'daily_register');
        abort_unless(VisitorReports::exists($type), 404);
        $this->allowed($request, $type);
        abort_unless($request->user()->can('print-visitor-reports'), 403);
        $r = VisitorReports::build($request->user(), $type, $request->only(VisitorReports::keys()), in_array($format, ['pdf', 'print'], true) ? 5000 : 50000);
        $r['qr'] = \App\Support\PrintQrCode::dataUri(route('visitor-logs.reports.index', array_filter(['type' => $type] + $request->only(VisitorReports::keys()), fn ($v) => $v !== null && $v !== '')));
        log_activity('Visitor Logs report exported', ['type' => $type, 'format' => $format, 'rows' => $r['total_rows'], 'reference' => $r['reference'], 'filters' => $r['filters']], null, 'Visitor Logs');

        return $r;
    }

    private function pdfOf(array $r)
    {
        $landscape = count($r['columns']) > 8;
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
        $file = 'visitor-logs-' . $r['type'] . '-' . now()->format('Ymd-His') . '.' . $ext;
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
