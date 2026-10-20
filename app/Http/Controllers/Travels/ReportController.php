<?php

namespace App\Http\Controllers\Travels;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Travel;
use App\Models\TravelAgentPayment;
use App\Services\Travels\TravelLedger;
use App\Services\Travels\TravelReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/** Travel reports: the same WIMS report layout as every other report; print, PDF, Excel and CSV; every export is logged. The agent reports need the agents permission. */
class ReportController extends Controller
{
    private const KEYS = ['search', 'contact_id', 'currency', 'status', 'status_payment', 'scope', 'country', 'when', 'category', 'section', 'from', 'to', 'as_of', 'only_owing'];

    private const AGENT_TYPES = ['agent_balances', 'agent_statement', 'payments'];

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function allowed(Request $request, string $type): void
    {
        abort_unless($request->user()->can('read-travels'), 403);
        if (in_array($type, self::AGENT_TYPES, true)) {
            abort_unless($request->user()->can('read-travel-agents'), 403);
        }
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-travels'), 403);
        $me = $request->user();
        $catalog = array_values(array_filter(TravelReports::catalog(), fn ($c) => ! in_array($c['key'], self::AGENT_TYPES, true) || $me->can('read-travel-agents')));
        $type = (string) $request->query('type', 'register');
        $type = in_array($type, array_column($catalog, 'key'), true) ? $type : 'register';
        $filters = $request->only(self::KEYS);
        if ($request->boolean('preview')) {
            $this->allowed($request, $type);

            return response()->json(collect(TravelReports::build($me, $type, $filters, 300))->except('raw')->all());
        }

        return Inertia::render('Travels/Reports', [
            'catalog' => $catalog, 'type' => $type, 'filters' => $filters, 'currencies' => Travel::currencies(), 'categories' => array_merge([__('Ticket'), __('Visa')], \App\Models\TravelItem::categories()), 'paymentStatuses' => collect(TravelAgentPayment::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(),
            'scopes' => collect(Travel::SCOPES)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'countries' => Travel::query()->visibleTo($me)->whereNotNull('to_country')->distinct()->orderBy('to_country')->pluck('to_country'), 'homeCountry' => Travel::homeCountry(),
            'agents' => Contact::query()->whereIn('id', TravelLedger::agentIds() ?: [0])->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name]),
            'statuses' => collect(Travel::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(), 'can' => ['print' => $me->can('print-travels')],
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
        return 'travels-' . preg_replace('/[^a-z_]/', '', (string) $request->query('type', 'register')) . '-' . now()->format('Ymd-His') . '.pdf';
    }

    private function exportable(Request $request, string $format): array
    {
        $type = (string) $request->query('type', 'register');
        abort_unless(TravelReports::exists($type), 404);
        $this->allowed($request, $type);
        abort_unless($request->user()->can('print-travels'), 403);
        $r = TravelReports::build($request->user(), $type, $request->only(self::KEYS), $format === 'pdf' || $format === 'print' ? 5000 : 50000);
        // the QR code reopens this same report (with its filters) for someone who is signed in and allowed to see it
        $r['qr'] = \App\Support\PrintQrCode::dataUri(route('travels.reports.index', array_filter(['type' => $type] + $request->only(self::KEYS), fn ($v) => $v !== null && $v !== '')));
        log_activity('Travel report exported', ['type' => $type, 'format' => $format, 'rows' => $r['total_rows'], 'reference' => $r['reference'], 'filters' => $r['filters']], null, 'Travels');

        return $r;
    }

    private function pdfOf(array $r)
    {
        $landscape = count($r['columns']) > 8; // up to eight columns fit a portrait page, so a short report keeps its footer and QR code on the same page
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
        $file = 'travels-' . $r['type'] . '-' . now()->format('Ymd-His') . '.' . $ext;
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
