<?php

namespace App\Http\Controllers\WeeklyAdministration;

use App\Http\Controllers\Controller;
use App\Models\AdminDocument;
use App\Models\AdminItem;
use App\Models\User;
use App\Services\Administration\AdminReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/** Administration reports: the same WIMS report layout as every other report; print, PDF, Excel and CSV; every export is logged. */
class ReportController extends Controller
{
    private const KEYS = ['search', 'status', 'from', 'to', 'item_id', 'user_id', 'beneficiary', 'section', 'charity', 'amount_min', 'amount_max', 'pay', 'pstatus', 'category', 'staff_id'];

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $me = $request->user();
        abort_unless($me->can('read-weekly-administration'), 403);
        $type = (string) $request->query('type', 'register');
        $type = AdminReports::exists($type) ? $type : 'register';
        $filters = $request->only(self::KEYS);
        if ($request->boolean('preview')) {
            return response()->json(collect(AdminReports::build($me, $type, $filters, 300))->except('raw')->all());
        }

        return Inertia::render('WeeklyAdministration/Reports', [
            'catalog' => AdminReports::catalog(), 'type' => $type, 'filters' => $filters,
            'statuses' => collect(array_merge(AdminDocument::STATUSES))->map(fn ($s) => ['value' => $s, 'label' => __($s)])->push(['value' => 'open', 'label' => __('Waiting (requested or approved)')])->values(),
            'items' => AdminItem::query()->orderBy('name')->get(['id', 'name'])->map(fn ($i) => ['value' => $i->id, 'label' => $i->name])->values(),
            'payStatuses' => collect(['Unpaid', 'Partially Paid', 'Paid'])->map(fn ($x) => ['value' => $x, 'label' => __($x)])->values(), 'paymentStatuses' => collect(\App\Models\AdminDocumentPayment::STATUSES)->map(fn ($x) => ['value' => $x, 'label' => __($x)])->values(),
            'categories' => \App\Services\Administration\AdminCategories::list(), 'staffOptions' => \App\Models\Staff::query()->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['value' => $x->id, 'label' => $x->name])->values(),
            'users' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values(), 'can' => ['print' => $me->can('print-weekly-administration')],
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
        return 'administration-' . preg_replace('/[^a-z_]/', '', (string) $request->query('type', 'register')) . '-' . now()->format('Ymd-His') . '.pdf';
    }

    private function exportable(Request $request, string $format): array
    {
        $type = (string) $request->query('type', 'register');
        abort_unless(AdminReports::exists($type), 404);
        abort_unless($request->user()->can('read-weekly-administration') && $request->user()->can('print-weekly-administration'), 403);
        $r = AdminReports::build($request->user(), $type, $request->only(self::KEYS), in_array($format, ['pdf', 'print'], true) ? 5000 : 50000);
        // the QR code reopens this same report (with its filters) for someone who is signed in and allowed to see it
        $r['qr'] = \App\Support\PrintQrCode::dataUri(route('weekly-administration.reports.index', array_filter(['type' => $type] + $request->only(self::KEYS), fn ($v) => $v !== null && $v !== '')));
        log_activity('Administration report exported', ['type' => $type, 'format' => $format, 'rows' => $r['total_rows'], 'reference' => $r['reference'], 'filters' => $r['filters']], null, 'Administration');

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
        $file = 'administration-' . $r['type'] . '-' . now()->format('Ymd-His') . '.' . $ext;
        $safe = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
        if ($r['raw'] === []) {
            return response()->streamDownload(function () use ($r) {
                $o = fopen('php://output', 'w');
                fputcsv($o, array_column($r['columns'], 'label'));
                fclose($o);
            }, preg_replace('/\.xlsx$/', '.csv', $file), ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $gen = function () use ($r, $safe) {
            foreach ($r['raw'] as $i => $row) {
                $o = [];
                foreach ($r['columns'] as $c) {
                    $o[$c['label']] = $c['key'] === 'no' ? $i + 1 : $safe($row[$c['key']] ?? '');
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
