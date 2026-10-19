<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctCategory;
use App\Models\User;
use App\Services\Accounts\AcctReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/** Reports with filters and PDF / Excel / CSV / print in the WIMS report layout. Every export is recorded. */
class ReportController extends AcctController
{
    private const FILTER_KEYS = ['account_id', 'from', 'to', 'currency', 'status', 'category_id', 'as_of', 'days', 'direction', 'user_id', 'group', 'q', 'record_id'];

    public function index(Request $request)
    {
        $this->need('read-acct-reports');
        $me = $this->me();
        $type = (string) $request->query('type', '');
        $filters = $request->only(self::FILTER_KEYS);
        if ($request->boolean('preview') && AcctReports::exists($type)) {
            $r = AcctReports::build($me, $type, $filters, $this->visibleAccountIds(), AcctReports::LIMIT_VIEW);

            return response()->json(collect($r)->except('raw')->all());
        }

        return Inertia::render('Accounts/Reports', $this->shared() + [
            'catalog' => AcctReports::catalog($me), 'groups' => collect(AcctReports::GROUPS)->map(fn ($l, $k) => ['key' => $k, 'label' => __($l)])->values(), 'type' => $type,
            'filters' => $filters + ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()], 'accounts' => $this->accountOptions(null, false),
            'categories' => AcctCategory::query()->orderBy('sort_order')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name]), 'users' => User::query()->where('is_active', 1)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'currencyOptions' => collect($this->currencies())->keys()->map(fn ($c) => ['value' => $c, 'label' => $c])->values(),
            'can' => ['export' => $me->can('export-acct-transactions')],
        ]);
    }

    /** Print opens the very same PDF that Download PDF gives, so what is printed is exactly what is downloaded. */
    public function print(Request $request)
    {
        $r = $this->exportable($request, 'print', AcctReports::LIMIT_PRINT);

        return $this->pdfOf($r)->stream($this->pdfName($r));
    }

    public function pdf(Request $request)
    {
        $r = $this->exportable($request, 'pdf', AcctReports::LIMIT_PRINT);

        return $this->pdfOf($r)->download($this->pdfName($r));
    }

    private function pdfName(array $r): string
    {
        return 'accounts-' . $r['type'] . '-' . now()->format('Ymd-His') . '.pdf';
    }

    private function pdfOf(array $r)
    {
        $pdf = Pdf::loadView('signatories.report-print', ['report' => $r, 'landscape' => AcctReports::landscape($r), 'isPdf' => true, 'autoPrint' => false, 'companyName' => $this->company(), 'printedBy' => $this->me()->name, 'printedAt' => now()->format('d M Y, H:i')])
            ->setPaper('a4', AcctReports::landscape($r) ? 'landscape' : 'portrait');
        $pdf->render();
        $d = $pdf->getDomPDF();
        $c = $d->getCanvas();
        $c->page_text($c->get_width() - 95, $c->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $d->getFontMetrics()->getFont('Helvetica'), 8.5, [0.61, 0.64, 0.69]);

        return $pdf;
    }

    public function csv(Request $request)
    {
        return $this->sheet($request, 'csv');
    }

    public function xlsx(Request $request)
    {
        return $this->sheet($request, 'xlsx');
    }

    private function sheet(Request $request, string $ext)
    {
        $r = $this->exportable($request, $ext, AcctReports::LIMIT_EXPORT);
        $file = 'accounts-' . $r['type'] . '-' . now()->format('Ymd-His') . '.' . $ext;
        $safe = fn ($v) => AcctReports::sheetSafe($v);
        $gen = function () use ($r, $safe) {
            foreach ($r['raw'] as $row) {
                $o = [];
                foreach ($r['columns'] as $c) {
                    $v = $row[$c['key']] ?? '';
                    $o[$c['label']] = $safe(is_bool($v) ? ($v ? 'Yes' : 'No') : $v);
                }
                yield $o;
            }
        };
        if ($r['raw'] === []) {
            return response()->streamDownload(function () use ($r) {
                $o = fopen('php://output', 'w');
                fputcsv($o, array_column($r['columns'], 'label'));
                fclose($o);
            }, preg_replace('/\.xlsx$/', '.csv', $file), ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $resp = (new FastExcel($gen()))->download($file);
        if ($ext === 'csv') {
            $resp->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        }

        return $resp;
    }

    private function exportable(Request $request, string $format, int $limit): array
    {
        $this->need('read-acct-reports');
        abort_unless($this->me()->can('export-acct-transactions'), 403);
        $type = (string) $request->query('type', '');
        abort_unless(AcctReports::exists($type), 404);
        $r = AcctReports::build($this->me(), $type, $request->only(self::FILTER_KEYS), $this->visibleAccountIds(), $limit);
        log_activity('Accounts report exported', ['type' => $type, 'format' => $format, 'rows' => $r['total_rows'], 'reference' => $r['reference'], 'filters' => $r['filters']], null, 'Accounts Tracking');
        \App\Services\Accounts\AcctAudit::log('report.exported', 'report', null, $r['reference'], null, ['type' => $type, 'format' => $format, 'rows' => $r['total_rows']], null, $this->me());

        return $r;
    }

    private function company(): string
    {
        return preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional($this->me()->account)->name);
    }
}
