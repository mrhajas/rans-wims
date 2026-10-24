<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectCertificateCollection;
use App\Models\ProjectCertificate;
use App\Models\ProjectCertificateContract;
use App\Models\ProjectCertificateContractAddendum;
use App\Models\ProjectCertificateLine;
use App\Models\ProjectCertificateRevision;
use App\Models\Warehouse;
use App\Services\ProjectCertificates\CertificateCalculationEngine;
use App\Services\ProjectCertificates\CertificateNumbering;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class ProjectCertificateController extends Controller
{
    /**
     * The default calculation structure (spec section 56's worked example)
     * seeded on every new certificate — QS can add/remove/edit lines freely
     * afterwards via lines.sync. `cumulative_certified` and `net_payment`
     * are reserved line_keys: if present, they drive the certificate's
     * denormalized cumulative/net columns at issue-time (see issue()); if a
     * customized structure omits them, issue() falls back to the first
     * total/subtotal line and the last line overall.
     */
    /**
     * Only the QS-editable lines — Input/Addition/Deduction/Reference.
     * Subtotal/Total are no longer part of any stored template: Builder.vue
     * generates them itself (a "Subtotal" line right after the inputs, a
     * final "Total / Net Payment" line at the end) and includes them in
     * every save, so every certificate's structure stays consistent without
     * the QS ever having to build a formula by hand.
     */
    public static function defaultLineTemplate(): array
    {
        return [
            ['line_key' => 'works_executed', 'label' => 'Value of Works Executed to Date', 'type' => 'input', 'sort_order' => 1, 'operands' => null, 'value' => 0],
            ['line_key' => 'materials_on_site', 'label' => 'Materials on Site', 'type' => 'input', 'sort_order' => 2, 'operands' => null, 'value' => 0],
            // These two were plain type:'input' lines — the "Works & Inputs"
            // section that autoSubtotalLine() always ADDS, with no sign
            // concept at all. A QS typing the amount the "Less:" label asks
            // for was silently inflating the subtotal instead of deducting
            // it. type:'reference' + source:'other' is the existing
            // mechanism (already used correctly for previous_certified
            // below) that shows a manually-typed amount AND a sign toggle,
            // defaulted here to '-' to match the label.
            ['line_key' => 'advance_recovery', 'label' => 'Less: Advance Recovery', 'type' => 'reference', 'sort_order' => 3, 'operands' => ['source' => 'other', 'sign' => '-'], 'value' => 0],
            ['line_key' => 'previous_certified', 'label' => 'Less: Previously Certified', 'type' => 'reference', 'sort_order' => 4, 'operands' => ['source' => 'previous_certificate_cumulative', 'sign' => '-'], 'value' => 0],
            ['line_key' => 'rans_contribution', 'label' => 'Less: RANS Contribution', 'type' => 'reference', 'sort_order' => 5, 'operands' => ['source' => 'other', 'sign' => '-'], 'value' => 0],
        ];
    }

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function globalIndex(Request $request)
    {
        abort_unless($request->user()->can('view-project-certificates'), 403);

        $filters = $request->only(['status', 'warehouse_id', 'search']);
        $filtered = $this->filteredGlobalQuery($filters)->get();

        // Grouped by currency rather than a flat sum — certificates can
        // span multiple contract currencies (e.g. TZS and USD projects),
        // and adding those raw numbers together would be meaningless.
        // "Unpaid"/"Overdue" only count certificates countsAsOutstanding()
        // still tracks — normally ISSUED, but also one Revised back to
        // draft that was issued before and still carries a genuine,
        // uncollected balance from payments recorded while it WAS issued
        // (see the model method's docblock). A CANCELLED certificate's
        // balance isn't money still owed (that's the whole point of
        // cancelling it). total_paid stays unrestricted — money that was
        // actually received doesn't stop being received just because the
        // certificate was later cancelled or revised.
        $stats = ['total_unpaid' => [], 'total_paid' => [], 'total_overdue' => []];
        foreach ($filtered as $c) {
            // outstanding()/totalPaid() are in the CERTIFICATE's own
            // currency (effectiveCurrency()), not necessarily the
            // contract's — bucketing by contract currency would silently
            // mix a USD certificate's balance into a TZS bucket whenever
            // the two differ.
            $currency = $c->effectiveCurrency();
            $outstanding = $c->outstanding();

            if ($outstanding > 0 && $c->countsAsOutstanding()) {
                $stats['total_unpaid'][$currency] = round(($stats['total_unpaid'][$currency] ?? 0) + $outstanding, 2);
            }
            $paid = $c->totalPaid();
            if ($paid > 0) {
                $stats['total_paid'][$currency] = round(($stats['total_paid'][$currency] ?? 0) + $paid, 2);
            }
            if ($c->countsAsOutstanding() && $c->paymentStatus() === 'overdue') {
                $stats['total_overdue'][$currency] = round(($stats['total_overdue'][$currency] ?? 0) + $outstanding, 2);
            }
        }

        $certificates = $this->filteredGlobalQuery($filters)
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ProjectCertificate $c) => $this->certificateRow($c));

        return Inertia::render('ProjectCertificates/Index', [
            'certificates' => new ProjectCertificateCollection($certificates),
            'filters' => $filters,
            'stats' => $stats,
            // "Main Warehouse" is a storage/stock hub, not a client project
            // — never belongs in a project filter dropdown here (same
            // status-based exclusion projectsWithoutContract() uses above).
            'warehouses' => Warehouse::ofAccount()->active()->visible()
                ->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])
                ->orderBy('name')->get(['id', 'name']),
            'statuses' => ProjectCertificate::statuses(),
        ]);
    }

    protected function filteredGlobalQuery(array $filters)
    {
        return ProjectCertificate::with(['warehouse:id,name,code', 'contract:id,currency', 'issuedBy:id,name', 'approvedBy:id,name', 'createdBy:id,name', 'payments'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->where(fn ($sub) => $sub->where('certificate_number', 'like', "%{$v}%")
                    ->orWhere('reference', 'like', "%{$v}%")
                    ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$v}%")));
            });
    }

    public function print(Request $request)
    {
        abort_unless($request->user()->can('export-project-certificates'), 403);

        $data = $this->globalExportPayload($request);
        log_activity('Project Certificate Register Printed', ['count' => count($data['rows'])], $request->user(), 'Project Certificate');

        return view('reports.project-certificates-list', $data + ['autoPrint' => true]);
    }

    public function download(Request $request)
    {
        abort_unless($request->user()->can('export-project-certificates'), 403);

        $data = $this->globalExportPayload($request);
        log_activity('Project Certificate Register Downloaded', ['count' => count($data['rows'])], $request->user(), 'Project Certificate');

        return Pdf::loadView('reports.project-certificates-list', $data)
            ->setPaper('a4', 'landscape')
            ->download('project-certificates-register-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function globalExportPayload(Request $request): array
    {
        $filters = $request->only(['status', 'warehouse_id', 'search']);
        $certificates = $this->filteredGlobalQuery($filters)->orderByDesc('id')->limit(1000)->get();

        return [
            'title' => 'Project Certificates · Register',
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'rows' => $certificates->map(fn (ProjectCertificate $c) => $this->certificateRow($c))->values()->all(),
        ];
    }

    /**
     * The org-wide Certificate Dashboard (separate from /warehouses, which
     * stays the general-purpose Projects/Stock page for staff who have
     * nothing to do with certificates) — one row per project that has a
     * contract set up, aggregating that project's certificates.
     */
    public function dashboard(Request $request)
    {
        abort_unless($request->user()->can('view-project-certificates'), 403);

        $filters = $request->only(['search', 'warehouse_id', 'client_id', 'date_from', 'date_to']);

        return Inertia::render('ProjectCertificates/Dashboard', [
            'rows' => $this->dashboardRows($filters),
            'filters' => $filters,
            // "Main Warehouse" is a storage/stock hub, not a client project
            // — never belongs in a project filter dropdown here (same
            // status-based exclusion projectsWithoutContract() uses above).
            'warehouses' => Warehouse::ofAccount()->active()->visible()
                ->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])
                ->orderBy('name')->get(['id', 'name']),
            'clients' => \App\Models\Contact::where('category', 'Clients')->orderBy('name')->get(['id', 'name']),
            'projectsWithoutContract' => $this->projectsWithoutContract(),
        ]);
    }

    /**
     * Projects (Warehouses) that don't have a certificate contract set up
     * yet — powers the Dashboard's "Awaiting Contract Setup" prompt.
     * "Main Warehouse" is a storage/stock hub, not a client project, so it
     * never belongs on a contract/certificate screen (same status-based
     * distinction Warehouse::scopeMainWarehouses() uses elsewhere).
     */
    protected function projectsWithoutContract()
    {
        return Warehouse::ofAccount()->active()->visible()
            ->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])
            ->whereNotIn('id', ProjectCertificateContract::pluck('warehouse_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    public function dashboardPrint(Request $request)
    {
        abort_unless($request->user()->can('export-project-certificates'), 403);

        $data = $this->dashboardExportPayload($request);
        log_activity('Project Certificate Dashboard Printed', ['count' => count($data['rows'])], $request->user(), 'Project Certificate');

        return view('reports.project-certificate-dashboard-list', $data + ['autoPrint' => true]);
    }

    public function dashboardDownload(Request $request)
    {
        abort_unless($request->user()->can('export-project-certificates'), 403);

        $data = $this->dashboardExportPayload($request);
        log_activity('Project Certificate Dashboard Downloaded', ['count' => count($data['rows'])], $request->user(), 'Project Certificate');

        return Pdf::loadView('reports.project-certificate-dashboard-list', $data)
            ->setPaper('a4', 'landscape')
            ->download('project-certificates-dashboard-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function dashboardExportPayload(Request $request): array
    {
        $filters = $request->only(['search', 'warehouse_id', 'client_id', 'date_from', 'date_to']);

        return [
            'title' => 'Project Certificates · Dashboard',
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'rows' => $this->dashboardRows($filters)->all(),
        ];
    }

    /**
     * Sums $amount($cert) across $items grouped by each certificate's OWN
     * currency (effectiveCurrency()) — never flat. A single project (or the
     * whole portfolio) can span multiple certificate currencies (e.g. an
     * early TZS certificate and a later USD one on the same contract), and
     * adding those raw numbers together produces a number with no
     * meaningful unit. Every "Total X" aggregate in this controller goes
     * through here so a mixed-currency project can't silently mislabel a
     * blended sum with one currency's symbol.
     */
    protected function bucketByCurrency(Collection $items, callable $amount): array
    {
        $totals = [];
        foreach ($items as $c) {
            $currency = $c->effectiveCurrency();
            $totals[$currency] = round(($totals[$currency] ?? 0) + $amount($c), 2);
        }

        // A currency that nets to zero (or less) across every certificate —
        // e.g. every USD certificate happens to be fully paid, so USD
        // contributes nothing to "Unpaid" — is dropped rather than shown as
        // a spurious "USD 0.00", matching globalIndex()'s own >0 guard.
        return array_filter($totals, fn ($v) => $v > 0);
    }

    /**
     * One row per project with a certificate contract, aggregating that
     * project's certificates. `search` matches project name/code, client
     * name, OR any of that project's certificate references — references
     * are a computed accessor (not a DB column, see
     * ProjectCertificate::reference()), so that last check happens in PHP
     * after loading each project's certs, not via a SQL LIKE. When a date
     * range is given, only certificates dated inside it count toward the
     * aggregates, and a project with none in range is dropped entirely —
     * the filter is a real "what happened in this window" narrowing, not
     * just a display tweak.
     */
    protected function dashboardRows(array $filters)
    {
        $search = $filters['search'] ?? null;

        $contracts = ProjectCertificateContract::with(['warehouse:id,name,code', 'client:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_contact_id', $v))
            ->when($search, fn ($q, $v) => $q->where(fn ($sub) => $sub
                ->whereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%"))
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$v}%"))))
            ->get()
            ->filter(fn ($c) => $c->warehouse !== null);

        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        return $contracts->map(function (ProjectCertificateContract $contract) use ($dateFrom, $dateTo, $search) {
            $certs = ProjectCertificate::where('warehouse_id', $contract->warehouse_id)->with('warehouse:id,code')
                ->when($dateFrom, fn ($q, $v) => $q->whereDate('certificate_date', '>=', $v))
                ->when($dateTo, fn ($q, $v) => $q->whereDate('certificate_date', '<=', $v))
                ->orderByDesc('certificate_number')->get();

            if (($dateFrom || $dateTo) && $certs->isEmpty()) {
                return null;
            }

            // A project-name/client-name search already matched via SQL
            // above; a reference-only search needs at least one certificate
            // under this project whose (computed) reference matches.
            if ($search
                && stripos($contract->warehouse->name, $search) === false
                && stripos($contract->warehouse->code, $search) === false
                && stripos($contract->client?->name ?? '', $search) === false
                && $certs->every(fn (ProjectCertificate $c) => stripos($c->reference, $search) === false)
            ) {
                return null;
            }

            // Summed per-certificate from outstanding() itself (issued only,
            // clamped to positive), not "certified minus paid" at the
            // project level — that naive subtraction goes negative the
            // moment a paid certificate is Revised back to draft (its
            // payments still count toward $totalPaid above, but it drops
            // out of the issued-only "certified" figure entirely) or
            // overpaid, neither of which is rare enough to leave unguarded
            // on a dashboard.
            $issuedCerts = $certs->where('status', ProjectCertificate::STATUS_ISSUED);
            // Unpaid uses countsAsOutstanding() (issued OR reopened-for-
            // revision-but-still-owed), a deliberately WIDER set than
            // $issuedCerts above — a certificate mid-revision keeps its
            // outstanding balance here even though it drops out of the
            // issued-only "certified" figure. See the model method's
            // docblock, and unpaidCertificates() which this must agree with.
            $outstandingCerts = $certs->filter(fn (ProjectCertificate $c) => $c->countsAsOutstanding());
            $totalPaid = $this->bucketByCurrency($certs, fn (ProjectCertificate $c) => $c->totalPaid());
            $totalUnpaid = $this->bucketByCurrency($outstandingCerts, fn (ProjectCertificate $c) => max(0, $c->outstanding()));
            $totalOverdue = $this->bucketByCurrency($certs->filter(fn (ProjectCertificate $c) => $c->paymentStatus() === 'overdue'), fn (ProjectCertificate $c) => $c->outstanding());
            $last = $certs->first();

            return [
                'warehouse_id' => $contract->warehouse_id,
                'warehouse_name' => $contract->warehouse->name,
                'warehouse_code' => $contract->warehouse->code,
                'client_name' => $contract->client?->name,
                'currency' => $contract->currency,
                'contract_value' => (float) $contract->revised_contract_sum,
                'certificates_count' => $certs->count(),
                'total_certified' => $this->bucketByCurrency($issuedCerts, fn (ProjectCertificate $c) => (float) $c->cumulative_certified_amount),
                'total_paid' => $totalPaid,
                'total_unpaid' => $totalUnpaid,
                'total_overdue' => $totalOverdue,
                'last_certificate_reference' => $last?->reference,
                'last_certificate_date' => optional($last?->certificate_date)->toDateString(),
            ];
        })->filter()->values();
    }

    /**
     * Every issued certificate anywhere with a positive outstanding balance
     * — the org-wide "who owes what" register, one row per certificate
     * (unlike the Dashboard's per-project aggregates).
     */
    public function unpaid(Request $request)
    {
        abort_unless($request->user()->can('view-financials-project-certificates'), 403);

        $filters = $request->only(['search', 'warehouse_id', 'client_id', 'currency', 'payment_status', 'date_from', 'date_to']);
        $filtered = $this->unpaidCertificates($filters);

        $stats = ['total_count' => $filtered->count(), 'total_outstanding' => [], 'overdue_count' => 0, 'overdue_outstanding' => []];
        $projectIds = [];
        foreach ($filtered as $c) {
            $currency = $c->effectiveCurrency();
            $outstanding = $c->outstanding();
            $stats['total_outstanding'][$currency] = round(($stats['total_outstanding'][$currency] ?? 0) + $outstanding, 2);
            if ($c->paymentStatus() === 'overdue') {
                $stats['overdue_count']++;
                $stats['overdue_outstanding'][$currency] = round(($stats['overdue_outstanding'][$currency] ?? 0) + $outstanding, 2);
            }
            $projectIds[] = $c->warehouse_id;
        }
        $stats['unique_projects'] = count(array_unique($projectIds));

        $page = (int) $request->query('page', 1);
        $perPage = 25;
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->map(fn (ProjectCertificate $c) => $this->unpaidRow($c))->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        $rows = paginated_response($paginator);

        return Inertia::render('ProjectCertificates/Unpaid', [
            'rows' => $rows,
            'filters' => $filters,
            'stats' => $stats,
            // "Main Warehouse" is a storage/stock hub, not a client project
            // — never belongs in a project filter dropdown here (same
            // status-based exclusion projectsWithoutContract() uses above).
            'warehouses' => Warehouse::ofAccount()->active()->visible()
                ->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])
                ->orderBy('name')->get(['id', 'name']),
            'clients' => \App\Models\Contact::where('category', 'Clients')->orderBy('name')->get(['id', 'name']),
            'currencies' => \App\Support\SupplierRecords\Currencies::all(),
        ]);
    }

    public function unpaidPrint(Request $request)
    {
        abort_unless($request->user()->can('view-financials-project-certificates'), 403);

        $data = $this->unpaidExportPayload($request);
        log_activity('Unpaid Certificates Register Printed', ['count' => count($data['rows'])], $request->user(), 'Project Certificate');

        return view('reports.project-certificate-unpaid-list', $data + ['autoPrint' => true]);
    }

    public function unpaidDownload(Request $request)
    {
        abort_unless($request->user()->can('view-financials-project-certificates'), 403);

        $data = $this->unpaidExportPayload($request);
        log_activity('Unpaid Certificates Register Downloaded', ['count' => count($data['rows'])], $request->user(), 'Project Certificate');

        return Pdf::loadView('reports.project-certificate-unpaid-list', $data)
            ->setPaper('a4', 'landscape')
            ->download('unpaid-certificates-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function unpaidExportPayload(Request $request): array
    {
        $filters = $request->only(['search', 'warehouse_id', 'client_id', 'currency', 'payment_status', 'date_from', 'date_to']);
        $rows = $this->unpaidCertificates($filters)->take(2000);
        $mapped = $rows->map(fn (ProjectCertificate $c) => $this->unpaidRow($c))->values();

        // One total per currency (TZS and any others in play) — a single
        // combined figure would silently add unlike currencies together.
        $totals = [];
        foreach ($mapped as $row) {
            $totals[$row['currency']] = round(($totals[$row['currency']] ?? 0) + $row['outstanding'], 2);
        }
        ksort($totals);

        return [
            'title' => 'Project Certificates · Unpaid Register',
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'rows' => $mapped->all(),
            'totals' => $totals,
        ];
    }

    /**
     * outstanding()/paymentStatus() are computed from the payments relation,
     * not DB columns — filtered and searched in PHP after loading, same
     * reasoning as dashboardRows() above and ProjectCertificatePaymentController's
     * applySearchFilter() (reference() is a computed accessor too).
     *
     * Not restricted to status=issued at the DB level — a certificate that
     * was issued, had a payment recorded, and was then Revised back to
     * draft (version bumped) still owes whatever's left, and must stay in
     * this register until the revision is resolved and re-issued (see
     * ProjectCertificate::countsAsOutstanding()). Only CANCELLED
     * certificates are excluded outright, at the DB level below.
     */
    protected function unpaidCertificates(array $filters)
    {
        $search = $filters['search'] ?? null;

        $certificates = ProjectCertificate::query()
            ->where('status', '!=', ProjectCertificate::STATUS_CANCELLED)
            ->with(['warehouse:id,name,code', 'contract.client:id,name', 'payments'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->whereHas('contract', fn ($c) => $c->where('client_contact_id', $v)))
            ->when($filters['currency'] ?? null, fn ($q, $v) => $q->whereHas('contract', fn ($c) => $c->where('currency', $v)))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('certificate_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('certificate_date', '<=', $v))
            ->orderByDesc('certificate_date')
            ->get()
            ->filter(fn (ProjectCertificate $c) => $c->countsAsOutstanding() && $c->outstanding() > 0);

        if ($filters['payment_status'] ?? null) {
            $certificates = $certificates->filter(fn (ProjectCertificate $c) => $c->paymentStatus() === $filters['payment_status']);
        }

        if ($search) {
            $certificates = $certificates->filter(function (ProjectCertificate $c) use ($search) {
                $haystacks = [$c->reference, $c->warehouse?->name, $c->warehouse?->code, $c->contract?->client?->name];
                foreach ($haystacks as $value) {
                    if ($value && stripos($value, $search) !== false) {
                        return true;
                    }
                }

                return false;
            });
        }

        return $certificates->values();
    }

    protected function unpaidRow(ProjectCertificate $c): array
    {
        return [
            'id' => $c->id,
            'certificate_reference' => $c->reference,
            'warehouse_id' => $c->warehouse_id,
            'warehouse_name' => $c->warehouse?->name,
            'warehouse_code' => $c->warehouse?->code,
            'client_name' => $c->contract?->client?->name,
            'certificate_date' => optional($c->certificate_date)->toDateString(),
            'payment_due_date' => optional($c->payment_due_date)->toDateString(),
            'currency' => $c->effectiveCurrency(),
            'net_payment_amount' => (float) $c->net_payment_amount,
            'total_paid' => $c->totalPaid(),
            'outstanding' => $c->outstanding(),
            'payment_status' => $c->paymentStatus(),
            // Not always 'issued' — a certificate reopened for revision
            // (see countsAsOutstanding()) still appears here while it owes
            // money, so the UI can flag it distinctly from a normal
            // awaiting-payment issued certificate.
            'certificate_status' => $c->status,
            'days_overdue' => $c->payment_due_date && $c->payment_due_date->isPast() ? (int) $c->payment_due_date->diffInDays(now()) : 0,
        ];
    }

    public function projectIndex(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('view-project-certificates'), 403);

        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->with(['client:id,name', 'consultant:id,name'])->first();
        $certificates = ProjectCertificate::where('warehouse_id', $warehouse->id)->with(['warehouse:id,name,code', 'issuedBy:id,name', 'approvedBy:id,name', 'createdBy:id,name', 'revisions', 'payments'])->orderByDesc('certificate_number')->get();

        $issuedCertificates = $certificates->where('status', ProjectCertificate::STATUS_ISSUED);
        // Grouped by each certificate's own currency (bucketByCurrency) —
        // this one project can itself span multiple certificate currencies
        // (e.g. an early TZS certificate and a later USD one), so a flat
        // sum would silently mix them into one meaningless figure.
        $totalCertified = $this->bucketByCurrency($issuedCertificates, fn (ProjectCertificate $c) => (float) $c->cumulative_certified_amount);
        $totalPaid = $this->bucketByCurrency($certificates, fn (ProjectCertificate $c) => $c->totalPaid());
        // Summed from each issued certificate's own outstanding() (clamped
        // to positive), not "certified minus paid" — see dashboardRows()'s
        // comment for why that subtraction can go negative (a Revised or
        // overpaid certificate). Uses countsAsOutstanding(), a WIDER set
        // than $issuedCertificates above — a certificate reopened for
        // revision keeps its unresolved balance here (see the model
        // method's docblock), so this stays consistent with the Unpaid
        // register (unpaidCertificates()).
        $outstandingCertificates = $certificates->filter(fn (ProjectCertificate $c) => $c->countsAsOutstanding());
        $outstanding = $this->bucketByCurrency($outstandingCertificates, fn (ProjectCertificate $c) => max(0, $c->outstanding()));

        return Inertia::render('ProjectCertificates/ProjectDashboard', [
            'warehouse' => $warehouse->only(['id', 'name', 'code']),
            'contract' => $contract,
            'certificates' => $this->certificateRowsWithVoidedRevisions($certificates),
            'stats' => [
                'contract_value' => $contract ? (float) $contract->revised_contract_sum : 0,
                'contract_currency' => $contract->currency ?? 'TZS',
                'certificates_count' => $certificates->count(),
                'total_certified' => $totalCertified,
                'total_paid' => $totalPaid,
                'outstanding' => $outstanding,
                'last_certificate' => $certificates->first() ? $this->certificateRow($certificates->first()) : null,
            ],
        ]);
    }

    public function projectPrint(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('export-project-certificates'), 403);

        $data = $this->projectExportPayload($request, $warehouse);
        log_activity('Project Certificates Printed', ['warehouse' => $warehouse->name, 'count' => count($data['rows'])], $warehouse, 'Project Certificate');

        return view('reports.project-certificates-list', $data + ['autoPrint' => true]);
    }

    public function projectDownload(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('export-project-certificates'), 403);

        $data = $this->projectExportPayload($request, $warehouse);
        log_activity('Project Certificates Downloaded', ['warehouse' => $warehouse->name, 'count' => count($data['rows'])], $warehouse, 'Project Certificate');

        return Pdf::loadView('reports.project-certificates-list', $data)
            ->setPaper('a4', 'landscape')
            ->download('project-certificates-' . $warehouse->code . '-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function projectExportPayload(Request $request, Warehouse $warehouse): array
    {
        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->with('client:id,name')->first();
        $certificates = ProjectCertificate::where('warehouse_id', $warehouse->id)
            ->with(['warehouse:id,name,code', 'contract:id,currency', 'issuedBy:id,name', 'createdBy:id,name', 'payments'])
            ->orderByDesc('certificate_number')
            ->get();

        $issuedCertificates = $certificates->where('status', ProjectCertificate::STATUS_ISSUED);
        // Grouped by each certificate's own currency — see bucketByCurrency().
        $totalCertified = $this->bucketByCurrency($issuedCertificates, fn (ProjectCertificate $c) => (float) $c->cumulative_certified_amount);
        $totalPaid = $this->bucketByCurrency($certificates, fn (ProjectCertificate $c) => $c->totalPaid());
        // countsAsOutstanding(), not $issuedCertificates — see projectIndex()'s
        // comment; keeps this export consistent with the on-screen dashboard
        // and the Unpaid register.
        $outstandingCertificates = $certificates->filter(fn (ProjectCertificate $c) => $c->countsAsOutstanding());
        $totalUnpaid = $this->bucketByCurrency($outstandingCertificates, fn (ProjectCertificate $c) => max(0, $c->outstanding()));
        $totalOverdue = $this->bucketByCurrency($certificates->filter(fn (ProjectCertificate $c) => $c->paymentStatus() === 'overdue'), fn (ProjectCertificate $c) => $c->outstanding());

        return [
            'title' => 'Project Certificates · ' . $warehouse->name,
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'rows' => $certificates->map(fn (ProjectCertificate $c) => $this->certificateRow($c))->values()->all(),
            // Only the per-project export gets these — the global register
            // spans every currency across every project, where a single set
            // of summary cards wouldn't mean anything.
            'stats' => [
                'currency' => $contract->currency ?? 'TZS',
                'client_name' => $contract?->client?->name,
                'contract_value' => (float) ($contract->revised_contract_sum ?? 0),
                'certificates_count' => $certificates->count(),
                'total_certified' => $totalCertified,
                'total_paid' => $totalPaid,
                'total_unpaid' => $totalUnpaid,
                'total_overdue' => $totalOverdue,
            ],
        ];
    }

    protected function certificateRow(ProjectCertificate $c): array
    {
        return [
            'id' => $c->id,
            'warehouse_id' => $c->warehouse_id,
            'warehouse_name' => $c->warehouse?->name,
            'certificate_number' => $c->certificate_number,
            'reference' => $c->reference,
            'certificate_type' => $c->certificate_type,
            'status' => $c->status,
            'payment_status' => $c->paymentStatus(),
            'valuation_date' => optional($c->valuation_date)->toDateString(),
            'certificate_date' => optional($c->certificate_date)->toDateString(),
            'payment_due_date' => optional($c->payment_due_date)->toDateString(),
            // net_payment_amount/outstanding/total_paid (below) are all
            // denominated in the CERTIFICATE's own currency (see
            // ProjectCertificate::amountInTzs()'s docblock) — using the
            // contract's currency here would mislabel every one of them
            // whenever a certificate's currency differs from its contract's
            // (Builder.vue lets a QS pick either). `cert_currency` used to
            // duplicate this under a second key for one conditional
            // sub-line; now that this key itself is correct there's only
            // one currency for Show.vue's money() helper to ever read.
            'currency' => $c->effectiveCurrency(),
            'cumulative_certified_amount' => (float) $c->cumulative_certified_amount,
            'net_payment_amount' => (float) $c->net_payment_amount,
            'cert_currency' => $c->effectiveCurrency(),
            'exchange_rate' => $c->exchange_rate !== null ? (float) $c->exchange_rate : null,
            'amount_in_tzs' => $c->amountInTzs(),
            'total_paid' => $c->totalPaid(),
            'total_paid_in_tzs' => $c->totalPaidInTzs(),
            'outstanding' => $c->outstanding(),
            'version' => $c->version,
            // Only shown once a certificate has actually been revised —
            // stamping "-A" on every never-revised certificate would imply
            // a "-B" exists somewhere when it never did.
            'version_letter' => $c->version > 1 ? $c->versionLetter() : null,
            'issued_at' => optional($c->issued_at)->toDateString(),
            'issued_by_name' => $c->relationLoaded('issuedBy') ? $c->issuedBy?->name : null,
            'approved_by_name' => $c->relationLoaded('approvedBy') ? $c->approvedBy?->name : null,
            'created_by_name' => $c->relationLoaded('createdBy') ? $c->createdBy?->name : null,
        ];
    }

    /**
     * A revised certificate's PAST versions aren't separate rows in the
     * project_certificates table (only the live row is ever updated —
     * see revise()), so the project's certificate list would otherwise
     * only ever show the current version, silently hiding that (e.g.)
     * "...00002" used to mean something different before it was revised.
     * This inserts one read-only "voided" row per past version — sourced
     * from that version's ProjectCertificateRevision snapshot — right
     * after its live certificate's row, each pointing forward to whichever
     * version actually superseded it (the next revision's version, or the
     * live certificate if this was the most recent revision).
     */
    protected function certificateRowsWithVoidedRevisions(Collection $certificates): Collection
    {
        $rows = collect();

        foreach ($certificates as $c) {
            $rows->push($this->certificateRow($c));

            $revisions = $c->revisions->sortByDesc('version');
            foreach ($revisions as $revision) {
                $nextRevision = $revisions->firstWhere('version', $revision->version + 1);
                $supersededByLetter = $nextRevision
                    ? ProjectCertificate::letterForVersion($nextRevision->version)
                    : $c->versionLetter();

                $snapshot = $this->normalizeRevisionSnapshot($revision->snapshot);
                $snapshotCert = $snapshot['certificate'] ?? [];

                $rows->push([
                    'id' => 'voided-'.$revision->id,
                    'is_voided' => true,
                    'warehouse_id' => $c->warehouse_id,
                    'certificate_number' => $c->certificate_number,
                    'reference' => $c->reference,
                    'version_letter' => ProjectCertificate::letterForVersion($revision->version),
                    'status' => 'voided',
                    'certificate_date' => $snapshotCert['certificate_date'] ?? null,
                    'currency' => $snapshotCert['currency'] ?? $c->effectiveCurrency(),
                    'net_payment_amount' => (float) ($snapshotCert['net_payment_amount'] ?? 0),
                    'superseded_by_id' => $c->id,
                    'superseded_by_reference' => $c->reference.'-'.$supersededByLetter,
                ]);
            }
        }

        return $rows->values();
    }

    public function create(Request $request, Warehouse $warehouse)
    {
        $this->authorize('create', ProjectCertificate::class);

        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->with('addendums')->first();
        abort_unless($contract, 422, 'Set up this project\'s contract details before creating a certificate.');

        $numbering = app(CertificateNumbering::class);
        $nextCertificateNumber = $numbering->nextNumber($warehouse->id);

        $previous = ProjectCertificate::where('warehouse_id', $warehouse->id)
            ->orderByDesc('certificate_number')
            ->with('lines')
            ->first();

        // Separate from $previous above (used only to carry the LINE
        // STRUCTURE forward, from whatever certificate is most recent
        // regardless of status — a QS building off an in-progress draft's
        // structure is fine). The reference-line VALUES below must match
        // CertificateCalculationEngine::referenceContext(), which only ever
        // looks at the most recent ISSUED certificate — a draft/mid-revision
        // certificate has no binding cumulative_certified_amount yet. Using
        // the unfiltered $previous here would let the Builder's live preview
        // show a stale or zeroed "Previous Certificate" value that the
        // server recomputes to something different the moment it saves.
        $previousIssued = ProjectCertificate::where('warehouse_id', $warehouse->id)
            ->where('status', ProjectCertificate::STATUS_ISSUED)
            ->where('certificate_number', '<', $nextCertificateNumber)
            ->orderByDesc('certificate_number')
            ->first();

        // Same "approved addendums only" rule as the Contract Setup page's
        // own Total Project Amount (see ProjectCertificateContractController
        // ::edit()) — a QS building a certificate off this card should see
        // the actual current contract value, not just the pre-addendum
        // Revised Sum, which never moves once an addendum is approved.
        $approvedAddendumSum = round((float) $contract->addendums->where('status', ProjectCertificateContractAddendum::STATUS_APPROVED)->sum('total_amount'), 2);
        $totalContractSum = (float) $contract->revised_contract_sum;

        return Inertia::render('ProjectCertificates/Builder', [
            'warehouse' => $warehouse->only(['id', 'name', 'code']),
            'contract' => $contract->load(['client:id,name', 'consultant:id,name']),
            'addendumStats' => [
                'total_contract_sum' => $totalContractSum,
                'total_addendum_sum' => $approvedAddendumSum,
                'total_project_amount' => round($totalContractSum + $approvedAddendumSum, 2),
            ],
            'nextCertificateNumber' => $nextCertificateNumber,
            'defaultLines' => $previous ? self::linesFromPrevious($previous) : self::defaultLineTemplate(),
            'prefilledFromCertificateNumber' => $previous?->certificate_number,
            // So a Reference line's "Previous Certificate" source can show
            // its real value the moment it's picked, client-side, instead
            // of staying blank until save — mirrors exactly what
            // CertificateCalculationEngine::referenceContext() resolves
            // server-side for that same source. Sourced from $previousIssued
            // (not $previous above, which ignores status) so the preview
            // never disagrees with what the engine actually computes on save.
            'previousCertificateCumulative' => $previousIssued ? (float) $previousIssued->cumulative_certified_amount : 0,
            'previousCertificateNetPaid' => $previousIssued ? (float) $previousIssued->payments()->sum('amount') : 0,
            'lineTypes' => ProjectCertificateLine::types(),
            'currencies' => \App\Support\SupplierRecords\Currencies::all(),
        ]);
    }

    /**
     * Rebuilding the same graph a QS already spent time constructing every
     * certificate (retention/percentage lines, custom terms, etc.) from
     * scratch every period was the actual complaint — so structure carries
     * forward from the previous certificate; `value` resets to 0 on every
     * line — including `input` lines — since works-executed/materials-on-
     * site/advance are period-specific figures that must never silently
     * repeat a prior period's number.
     *
     * Only Input/Addition/Deduction/Reference lines are QS-editable now
     * (Builder.vue generates Subtotal/Total itself — see
     * defaultLineTemplate()). A certificate built before this simplification
     * can still carry the older Percentage/Formula/Subtotal/Total types;
     * those are dropped here rather than copied forward (Subtotal/Total get
     * regenerated fresh anyway, and Percentage/Formula have no equivalent in
     * the simplified model — the QS re-enters that figure as a plain Input
     * this period). Addition/Deduction inherited from that older structure
     * only ever needed one term to mean anything sensible in the new
     * single-label-mirror UI, so multi-term formulas collapse to their
     * first term.
     */
    public static function linesFromPrevious(ProjectCertificate $previous): array
    {
        $editableTypes = [
            ProjectCertificateLine::TYPE_INPUT,
            ProjectCertificateLine::TYPE_ADDITION,
            ProjectCertificateLine::TYPE_DEDUCTION,
            ProjectCertificateLine::TYPE_REFERENCE,
        ];

        return $previous->lines->sortBy('sort_order')->values()
            ->filter(fn (ProjectCertificateLine $l) => in_array($l->type, $editableTypes, true))
            ->values()
            ->map(function (ProjectCertificateLine $l, int $i) {
                $operands = $l->operands;

                if (in_array($l->type, [ProjectCertificateLine::TYPE_ADDITION, ProjectCertificateLine::TYPE_DEDUCTION], true)) {
                    $firstTerm = $operands['terms'][0] ?? null;
                    // Operator is always "+" here — the mirrored label's
                    // raw value, unsigned. Addition/Deduction's sign is
                    // applied exactly once, by Builder.vue's autoTotalLine,
                    // when this line rolls into the Total; baking it in
                    // here too would double-apply it.
                    $operands = ['terms' => [[
                        'ref' => $firstTerm['ref'] ?? null,
                        'value' => $firstTerm['value'] ?? 0,
                        'operator' => '+',
                    ]]];
                } elseif ($l->type === ProjectCertificateLine::TYPE_REFERENCE && ! isset($operands['sign'])) {
                    // Older reference lines predate the sign toggle — every
                    // one actually in use today is a "Less: ..." deduction
                    // (see REFERENCE_SOURCES usage across seeded/live
                    // certificates), so that's the safe default.
                    $operands['sign'] = '-';
                }

                return [
                    'line_key' => $l->line_key,
                    'label' => $l->label,
                    'type' => $l->type,
                    'sort_order' => $i + 1,
                    'operands' => $operands,
                    'value' => 0,
                ];
            })->all();
    }

    public function store(Request $request, Warehouse $warehouse)
    {
        $this->authorize('create', ProjectCertificate::class);

        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->firstOrFail();
        $data = $this->validateCertificate($request);
        $lines = $this->validateLines($request);

        $numbering = app(CertificateNumbering::class);
        $number = $data['certificate_number'] ?? $numbering->nextNumber($warehouse->id);
        abort_if($numbering->isDuplicate($warehouse->id, $number), 422, "Certificate No. {$number} already exists for this project.");

        $certificate = DB::transaction(function () use ($warehouse, $contract, $data, $lines, $number, $request) {
            $year = (int) \Illuminate\Support\Carbon::parse($data['certificate_date'])->format('Y');
            $reference = app(CertificateNumbering::class)->buildReference($warehouse->code, $number, $year);

            $cert = ProjectCertificate::create([
                'warehouse_id' => $warehouse->id,
                'contract_id' => $contract->id,
                'certificate_number' => $number,
                'reference' => $reference,
                'certificate_type' => $data['certificate_type'],
                'status' => ProjectCertificate::STATUS_DRAFT,
                'valuation_date' => $data['valuation_date'],
                'certificate_date' => $data['certificate_date'],
                'payment_due_date' => $data['payment_due_date'] ?? null,
                'period_covered' => $data['period_covered'] ?? null,
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'currency' => $data['currency'] ?? $contract->currency,
                'exchange_rate' => strtoupper($data['currency'] ?? $contract->currency) === 'TZS' ? 1 : ($data['exchange_rate'] ?? 1),
                'created_by' => $request->user()->id,
            ]);

            $this->saveLines($cert, $lines);

            return $cert;
        });

        if ($request->hasFile('attachments')) {
            $certificate->saveAttachments($request->file('attachments'), 'certificate');
        }

        app(CertificateCalculationEngine::class)->evaluate($certificate);

        log_activity('Project Certificate Created', ['warehouse' => $warehouse->name, 'certificate_number' => $number], $certificate, 'Project Certificate');
        $this->alert('created', $certificate, $request, __('Certificate Created'));

        return redirect()->route('project-certificates.show', $certificate->id)->with('message', __('Certificate created as draft.'));
    }

    public function show(Request $request, ProjectCertificate $certificate)
    {
        $this->authorize('view', $certificate);

        $certificate->load(['warehouse:id,name,code', 'contract.client:id,name', 'contract.clients.client:id,name', 'contract.consultant:id,name', 'lines', 'payments.recordedBy:id,name', 'payments.attachments', 'attachments', 'createdBy:id,name', 'submittedBy:id,name', 'approvedBy:id,name', 'issuedBy:id,name', 'revisions']);
        // The payments->reference accessor needs payment->certificate->warehouse;
        // every payment here belongs to this same already-loaded certificate,
        // so wire the relation back in memory instead of an N+1 re-fetch.
        $certificate->payments->each(fn ($p) => $p->setRelation('certificate', $certificate));

        return Inertia::render('ProjectCertificates/Show', [
            'certificate' => $this->certificateDetail($certificate),
            'can' => [
                'update' => $request->user()->can('update', $certificate),
                'submit' => $request->user()->can('submit', $certificate),
                'approve' => $request->user()->can('approve', $certificate),
                'issue' => $request->user()->can('issue', $certificate),
                'revise' => $request->user()->can('revise', $certificate),
                'recordPayment' => $request->user()->can('recordPayment', $certificate),
                'editPayment' => $request->user()->can('editPayment', $certificate),
                'cancel' => $request->user()->can('cancel', $certificate),
                'viewFinancials' => $request->user()->can('viewFinancials', $certificate),
            ],
            'currencies' => \App\Support\SupplierRecords\Currencies::all(),
            // A few sensible defaults merged with every distinct method
            // already used, so the field behaves like a real dropdown once
            // a few payments exist, while still accepting a brand new one
            // (freeform) the first time.
            'paymentMethods' => collect(['Bank Transfer', 'Cash', 'Cheque', 'Mobile Money'])
                ->merge(\App\Models\ProjectCertificatePayment::whereNotNull('method')->distinct()->pluck('method'))
                ->unique()->sort()->values(),
            // This project's own funding clients only (contract.clients,
            // the multi-client funding table) — not every client in the
            // system. A payment on this certificate should be attributed
            // to one of the parties actually funding this project.
            'clients' => $certificate->contract
                ? $certificate->contract->clients->pluck('client.name')->filter()->unique()->values()
                : collect(),
        ]);
    }

    /**
     * Certificates can carry their own supporting attachments (uploaded via
     * Builder.vue's AttachmentPicker at create time) — these two mirror
     * ProjectCertificateContractController's downloadAttachment()/
     * destroyAttachment() exactly, same ownership check pattern, since
     * before this the only way in was upload: nothing could retrieve or
     * remove a certificate attachment once saved.
     */
    public function downloadAttachment(Request $request, ProjectCertificate $certificate, Attachment $attachment)
    {
        $this->authorize('view', $certificate);
        abort_unless((int) $attachment->model_id === $certificate->id && $attachment->model_type === ProjectCertificate::class, 404);

        $name = $attachment->title ?: $attachment->filename;

        return Storage::disk($attachment->disk)->download($attachment->filepath, $name);
    }

    public function destroyAttachment(Request $request, ProjectCertificate $certificate, Attachment $attachment)
    {
        $this->authorize('update', $certificate);
        abort_unless((int) $attachment->model_id === $certificate->id && $attachment->model_type === ProjectCertificate::class, 404);

        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        log_activity('Project Certificate Attachment Removed', ['certificate_number' => $certificate->certificate_number, 'filename' => $attachment->filename], $certificate, 'Project Certificate');

        return back()->with('message', __('Attachment removed.'));
    }

    protected function certificateDetail(ProjectCertificate $c): array
    {
        return array_merge($this->certificateRow($c), [
            'description' => $c->description,
            'notes' => $c->notes,
            'period_covered' => $c->period_covered,
            'is_locked' => $c->is_locked,
            'payment_status_override' => $c->payment_status_override,
            'status_override_reason' => $c->status_override_reason,
            'contract' => $c->contract ? [
                'id' => $c->contract->id,
                'currency' => $c->contract->currency,
                'original_contract_sum' => (float) $c->contract->original_contract_sum,
                'revised_contract_sum' => (float) $c->contract->revised_contract_sum,
                'client_name' => $c->contract->client?->name,
                'consultant_name' => $c->contract->consultant?->name,
                'contractor_name' => $c->contract->contractorDisplayName(),
                'contract_reference' => $c->contract->contract_reference,
            ] : null,
            'lines' => $c->lines->map(fn (ProjectCertificateLine $l) => [
                'id' => $l->id, 'line_key' => $l->line_key, 'label' => $l->label, 'type' => $l->type,
                'sort_order' => $l->sort_order, 'operands' => $l->operands, 'value' => (float) $l->value, 'is_locked' => $l->is_locked,
            ])->values(),
            'payments' => $c->payments->map(fn ($p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'payment_date' => optional($p->payment_date)->toDateString(),
                'reference' => $p->reference, 'reference_number' => $p->reference_number, 'method' => $p->method, 'paid_by' => $p->paid_by,
                'notes' => $p->notes, 'recorded_by_name' => $p->recordedBy?->name,
                'paid_currency' => $p->paid_currency, 'paid_amount' => $p->paid_amount !== null ? (float) $p->paid_amount : null,
                'exchange_rate' => $p->exchange_rate !== null ? (float) $p->exchange_rate : null,
                'attachments' => $p->attachments->map(fn (Attachment $a) => [
                    'id' => $a->id,
                    'name' => $a->title ?: $a->filename,
                    'download_url' => route('project-certificates.payments.attachments.download', [$c->id, $p->id, $a->id]),
                    'delete_url' => route('project-certificates.payments.attachments.destroy', [$c->id, $p->id, $a->id]),
                ])->values(),
            ])->values(),
            'attachments' => $c->attachments->map(fn (Attachment $a) => [
                'id' => $a->id,
                'name' => $a->title ?: $a->filename,
                'download_url' => route('project-certificates.attachments.download', [$c->id, $a->id]),
                'delete_url' => route('project-certificates.attachments.destroy', [$c->id, $a->id]),
            ])->values(),
            'created_by_name' => $c->createdBy?->name,
            'submitted_by_name' => $c->submittedBy?->name,
            'approved_by_name' => $c->approvedBy?->name,
            'issued_by_name' => $c->issuedBy?->name,
            // `snapshot` included so Show.vue can diff each revision against
            // whatever came right after it (the next-more-recent revision,
            // or the live certificate for the most recent one) — computed
            // client-side since it's purely a display concern and the data
            // needed (this certificate's own lines) is already on the page.
            'revisions' => $c->revisions->map(fn (ProjectCertificateRevision $r) => [
                'version' => $r->version, 'changed_by_name' => $r->changedBy?->name, 'reason' => $r->reason, 'created_at' => $r->created_at?->toDateTimeString(),
                'snapshot' => $this->normalizeRevisionSnapshot($r->snapshot),
            ])->values(),
        ]);
    }

    /**
     * revise() snapshots raw model attributes via $certificate->only([...]),
     * so a `date`-cast column round-trips through the JSON `snapshot`
     * column as a full ISO datetime *in UTC* (e.g.
     * "2026-08-12T21:00:00.000000Z" for a date that's "2026-08-13" in the
     * app's own timezone) — Carbon's default JSON serialization, not a
     * bug in revise() itself. Show.vue's revision diff compares this
     * against the LIVE certificate's dates, which arrive already
     * formatted as a plain local Y-m-d string (certificateRow(), above).
     * Comparing a raw UTC timestamp against a local date string either
     * client-side or without this step would misreport genuinely
     * unchanged dates as "changed" depending on how far UTC and the app's
     * timezone happen to sit apart that day — reformatted here, once,
     * server-side, so the frontend only ever compares like with like.
     */
    protected function normalizeRevisionSnapshot(array $snapshot): array
    {
        foreach (['valuation_date', 'certificate_date', 'payment_due_date'] as $field) {
            if (! empty($snapshot['certificate'][$field])) {
                $snapshot['certificate'][$field] = \Illuminate\Support\Carbon::parse($snapshot['certificate'][$field])->setTimezone(config('app.timezone'))->toDateString();
            }
        }

        return $snapshot;
    }

    public function update(Request $request, ProjectCertificate $certificate)
    {
        if ($response = $this->guardWorkflowStatus($certificate, ProjectCertificate::STATUS_DRAFT, __('Only a draft certificate can be edited · revise an issued certificate first.'))) {
            return $response;
        }
        $this->authorize('update', $certificate);

        $data = $this->validateCertificate($request);

        $certificate->update([
            'certificate_type' => $data['certificate_type'],
            'valuation_date' => $data['valuation_date'],
            'certificate_date' => $data['certificate_date'],
            'payment_due_date' => $data['payment_due_date'] ?? null,
            'period_covered' => $data['period_covered'] ?? null,
            'description' => $data['description'] ?? null,
            'notes' => $data['notes'] ?? null,
            'currency' => $data['currency'] ?? $certificate->currency,
            'exchange_rate' => strtoupper($data['currency'] ?? $certificate->currency) === 'TZS' ? 1 : ($data['exchange_rate'] ?? $certificate->exchange_rate ?? 1),
        ]);

        log_activity('Project Certificate Updated', ['certificate_number' => $certificate->certificate_number], $certificate, 'Project Certificate');

        \App\Services\CertificateNotifier::certificate('updated', $certificate->fresh(), $request->user(), __('Certificate Edited'), $certificate->reference . ' was edited by ' . $request->user()->name . '.');

        return back()->with('message', __('Certificate updated.'));
    }

    /**
     * A friendly, in-page version of the workflow-sequence rule Gate::before
     * also enforces (see AuthServiceProvider) — that carve-out is what
     * actually makes the rule unbypassable (including by Super Admin) and
     * correctly hides the wrong-for-this-status action buttons in the UI;
     * this check exists purely so hitting the endpoint anyway (a stale tab,
     * a double-click racing another tab's action) surfaces a clear toast
     * instead of falling through to authorize()'s generic 403 error page.
     */
    protected function guardWorkflowStatus(ProjectCertificate $certificate, string $expectedStatus, string $message)
    {
        if ($certificate->status !== $expectedStatus) {
            // flash.error (not withErrors()) so the message shows verbatim
            // in the global toast (FlashMessages.vue renders flash.error
            // directly) — Show.vue's act() has no per-action onError
            // handler, so an errors-bag entry would only ever surface as
            // the toast's generic "There is one form error." fallback text.
            return back()->with('error', $message);
        }

        return null;
    }

    /** Hands a certificate event to the Project Certificates workflow page's settings (Who Gets Notified / Messages). */
    private function alert(string $event, ProjectCertificate $certificate, Request $request, string $title, array $ctx = []): void
    {
        \App\Services\Workflow\WorkflowMessenger::dispatch('certificates', $event, $certificate, $request->user(), $ctx + [
            'account_id' => $request->user()->account_id, 'owner_user_id' => (int) $certificate->created_by, 'title' => $title,
            'message' => $certificate->reference . ' - ' . $certificate->status . '.', 'remarks' => $request->input('reason') ?? $request->input('remarks'),
        ]);
    }

    public function submit(Request $request, ProjectCertificate $certificate)
    {
        if ($response = $this->guardWorkflowStatus($certificate, ProjectCertificate::STATUS_DRAFT, __('This certificate must be a draft before it can be submitted for review.'))) {
            return $response;
        }
        $this->authorize('submit', $certificate);

        $certificate->update(['status' => ProjectCertificate::STATUS_SUBMITTED, 'submitted_by' => $request->user()->id, 'submitted_at' => now()]);

        log_activity('Project Certificate Submitted', ['certificate_number' => $certificate->certificate_number], $certificate, 'Project Certificate');
        $this->alert('submitted', $certificate, $request, __('Certificate Submitted'));

        return back()->with('message', __('Certificate submitted for review.'));
    }

    public function approve(Request $request, ProjectCertificate $certificate)
    {
        if ($response = $this->guardWorkflowStatus($certificate, ProjectCertificate::STATUS_SUBMITTED, __('This certificate must be submitted for review before it can be approved.'))) {
            return $response;
        }
        $this->authorize('approve', $certificate);

        $certificate->update(['status' => ProjectCertificate::STATUS_APPROVED, 'approved_by' => $request->user()->id, 'approved_at' => now()]);

        log_activity('Project Certificate Approved', ['certificate_number' => $certificate->certificate_number], $certificate, 'Project Certificate');
        $this->alert('approved', $certificate, $request, __('Certificate Approved'));

        return back()->with('message', __('Certificate approved.'));
    }

    /**
     * Locks the certificate and stamps the two denormalized figures every
     * later certificate's "Previous Certified" reference relies on.
     * cumulative_certified prefers the reserved 'cumulative_certified'
     * line_key, falling back to the first total/subtotal line for a
     * customized structure; net_payment prefers 'net_payment', falling back
     * to the certificate's last line overall.
     */
    public function issue(Request $request, ProjectCertificate $certificate)
    {
        if ($response = $this->guardWorkflowStatus($certificate, ProjectCertificate::STATUS_APPROVED, __('This certificate must be approved before it can be issued.'))) {
            return $response;
        }
        $this->authorize('issue', $certificate);

        // Signatories: when the document setup requires a signature and blocks finalising, refuse to issue without it (nothing changes with the default setup).
        if ($blocked = \App\Services\Signatories\Documents\Support\RefusesWithoutSignatures::message('project_certificate', app(\App\Services\Signatories\Documents\ProjectCertificateDocument::class)->asIfIssued($certificate, (int) $request->user()->id), __('issued'))) {
            return back()->with('error', $blocked);
        }

        // Certificates can be created/approved out of numeric order (nothing
        // stops starting #2 while #1 is still approved), but a `reference`
        // line's "Previous Certified"/"Previous Paid" figures are only ever
        // resolved once, at whichever moment evaluate() last ran for THIS
        // certificate — which could be well before an earlier-numbered
        // certificate was actually issued. Issuing out of order would freeze
        // a stale (often zero) "previous certified" into net_payment_amount
        // permanently, since is_locked stops any further evaluate() calls.
        // Cumulative certification only means anything issued strictly in
        // sequence, so it's blocked here rather than silently mis-certified.
        $blockingPredecessor = ProjectCertificate::where('warehouse_id', $certificate->warehouse_id)
            ->where('certificate_number', '<', $certificate->certificate_number)
            ->whereNotIn('status', [ProjectCertificate::STATUS_ISSUED, ProjectCertificate::STATUS_CANCELLED])
            ->orderBy('certificate_number')
            ->first();
        if ($blockingPredecessor) {
            return back()->with('error', __('Certificate #:number must be issued (or cancelled) before this one · certificates must be issued in order so cumulative totals stay correct.', ['number' => $blockingPredecessor->certificate_number]));
        }

        // Re-evaluate right before stamping the denormalized totals below,
        // so any `reference` line pulling "previous certified"/"previous
        // paid" reflects the CURRENT state of the predecessor certificate
        // (which may have been issued, or had payments recorded against it,
        // any time after this certificate's lines were last saved) rather
        // than whatever was true the last time store()/sync() ran.
        app(CertificateCalculationEngine::class)->evaluate($certificate);
        $certificate->refresh();

        $lines = $certificate->lines()->orderBy('sort_order')->get();

        // "Certified" means the certified value of work done — Subtotal
        // plus every Addition (an approved variation IS certified work) —
        // never a deduction/reference line (retention, advance recovery,
        // previously certified), since those reduce what's PAYABLE, not
        // what's CERTIFIED. An explicit `cumulative_certified` line_key
        // still wins if a certificate was ever given one directly.
        $cumulativeLine = $lines->firstWhere('line_key', 'cumulative_certified');
        if ($cumulativeLine) {
            $cumulativeCertified = (string) $cumulativeLine->value;
        } else {
            $subtotalLine = $lines->firstWhere('type', ProjectCertificateLine::TYPE_SUBTOTAL);
            $cumulativeCertified = $subtotalLine ? (string) $subtotalLine->value : '0.00';
            foreach ($lines->where('type', ProjectCertificateLine::TYPE_ADDITION) as $additionLine) {
                $cumulativeCertified = bcadd($cumulativeCertified, (string) $additionLine->value, 2);
            }
        }

        $netLine = $lines->firstWhere('line_key', 'net_payment') ?? $lines->last();

        $certificate->update([
            'status' => ProjectCertificate::STATUS_ISSUED,
            'issued_by' => $request->user()->id,
            'issued_at' => now(),
            'is_locked' => true,
            'cumulative_certified_amount' => (float) $cumulativeCertified,
            'net_payment_amount' => $netLine ? (float) $netLine->value : 0,
        ]);
        $certificate->lines()->update(['is_locked' => true]);

        log_activity('Project Certificate Issued', ['certificate_number' => $certificate->certificate_number, 'net_payment' => $certificate->net_payment_amount], $certificate, 'Project Certificate');
        $this->alert('issued', $certificate, $request, __('Certificate Issued'));

        return back()->with('message', __('Certificate issued.'));
    }

    /**
     * Snapshots the current issued state (never destroyed) before unlocking
     * for edits and bumping the version — req. 26/49/54.
     */
    public function revise(Request $request, ProjectCertificate $certificate)
    {
        if ($response = $this->guardWorkflowStatus($certificate, ProjectCertificate::STATUS_ISSUED, __('Only an issued certificate can be revised.'))) {
            return $response;
        }
        $this->authorize('revise', $certificate);

        $data = $request->validate(['reason' => 'required|string|max:1000']);

        DB::transaction(function () use ($certificate, $data, $request) {
            ProjectCertificateRevision::create([
                'certificate_id' => $certificate->id,
                'version' => $certificate->version,
                'snapshot' => [
                    'certificate' => $certificate->only([
                        'certificate_number', 'certificate_type', 'status', 'currency', 'exchange_rate',
                        'valuation_date', 'certificate_date', 'payment_due_date', 'period_covered',
                        'description', 'notes', 'cumulative_certified_amount', 'net_payment_amount',
                    ]),
                    'lines' => $certificate->lines()->get()->toArray(),
                ],
                'changed_by' => $request->user()->id,
                'reason' => $data['reason'],
            ]);

            $certificate->update([
                'status' => ProjectCertificate::STATUS_DRAFT,
                'version' => $certificate->version + 1,
                'is_locked' => false,
            ]);
            // Signatories: the signatures frozen when version N was issued belong to that version; the next issue freezes its own.
            \App\Models\SignatureSnapshot::query()->where('document_type', 'project_certificate')->where('record_id', $certificate->id)->delete();
            $certificate->lines()->update(['is_locked' => false]);
        });

        log_activity('Project Certificate Revised', ['certificate_number' => $certificate->certificate_number, 'new_version' => $certificate->version, 'reason' => $data['reason']], $certificate, 'Project Certificate');
        $this->alert('revised', $certificate, $request, __('Certificate Revised'));

        return back()->with('message', __('Certificate reopened as version :v. Previous version preserved.', ['v' => $certificate->version]));
    }

    public function cancel(Request $request, ProjectCertificate $certificate)
    {
        if (in_array($certificate->status, [ProjectCertificate::STATUS_ISSUED, ProjectCertificate::STATUS_CANCELLED], true)) {
            return back()->with('error', __('An issued certificate can\'t be cancelled directly · use Revise instead, or Cancel while it\'s still a draft.'));
        }
        $this->authorize('cancel', $certificate);

        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        $certificate->update(['status' => ProjectCertificate::STATUS_CANCELLED, 'notes' => trim(($certificate->notes ?? '') . "\nCancelled: " . ($data['reason'] ?? ''))]);

        log_activity('Project Certificate Cancelled', ['certificate_number' => $certificate->certificate_number, 'reason' => $data['reason'] ?? null], $certificate, 'Project Certificate');
        $this->alert('cancelled', $certificate, $request, __('Certificate Cancelled'));

        return back()->with('message', __('Certificate cancelled.'));
    }

    public function printSingle(Request $request, ProjectCertificate $certificate)
    {
        $this->authorize('export', $certificate);

        $data = $this->pdfPayload($certificate, $request->user()->name);
        log_activity('Project Certificate Printed', ['certificate_number' => $certificate->certificate_number], $certificate, 'Project Certificate');

        return view('reports.project-certificate', $data + ['autoPrint' => true]);
    }

    public function downloadSingle(Request $request, ProjectCertificate $certificate)
    {
        $this->authorize('export', $certificate);

        $data = $this->pdfPayload($certificate, $request->user()->name);
        log_activity('Project Certificate Downloaded', ['certificate_number' => $certificate->certificate_number], $certificate, 'Project Certificate');

        $pdf = Pdf::loadView('reports.project-certificate', $data)->setPaper('a4', 'portrait');
        $this->stampPageNumbers($pdf);

        return $pdf->download('certificate-' . $certificate->warehouse->code . '-' . $certificate->certificate_number . '.pdf');
    }

    /**
     * dompdf 3.x doesn't implement CSS Paged Media's counter(page)/
     * counter(pages) inside @page margin boxes (confirmed by rendering — it
     * silently produces nothing), so "Page X of Y" on the generated PDF has
     * to go through dompdf's own canvas API instead. That API queues onto
     * whatever page objects exist in the canvas at call time
     * (Adapter\CPDF::processPageScript iterates $this->_pages) — calling it
     * before ->render() only sees the single placeholder page dompdf starts
     * with, so the text silently never appears past (or even on) the real
     * pages. render() must run first so the real, final page list exists.
     */
    protected function stampPageNumbers(\Barryvdh\DomPDF\PDF $pdf): void
    {
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica');
        $width = $canvas->get_width();
        $height = $canvas->get_height();

        $canvas->page_text($width - 95, $height - 42, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8.5, [0.61, 0.64, 0.69]);
    }

    protected function pdfPayload(ProjectCertificate $certificate, ?string $generatedBy = null): array
    {
        $certificate->load([
            'warehouse:id,name,code,address', 'contract.client:id,name', 'contract.consultant:id,name', 'lines',
            'createdBy:id,name', 'approvedBy:id,name', 'issuedBy:id,name',
        ]);

        return [
            'certificate' => $certificate,
            'warehouse' => $certificate->warehouse,
            'contract' => $certificate->contract,
            'lines' => $certificate->lines()->orderBy('sort_order')->get(),
            'companyName' => optional($certificate->warehouse->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $generatedBy,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('project_certificate', $certificate),
        ];
    }

    protected function validateCertificate(Request $request): array
    {
        $data = $request->validate([
            'certificate_number' => 'nullable|integer|min:1',
            'certificate_type' => 'required|string|max:190',
            'valuation_date' => 'required|date',
            'certificate_date' => 'required|date',
            'payment_due_date' => 'nullable|date|after_or_equal:certificate_date',
            'period_covered' => 'nullable|string|max:190',
            'description' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'currency' => 'nullable|string|max:10',
            'exchange_rate' => 'nullable|numeric|min:0.000001',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,jpeg,png,pdf,doc,docx,xlsx'),
        ]);

        if (strtoupper($data['currency'] ?? 'TZS') !== 'TZS' && empty($data['exchange_rate'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'exchange_rate' => __('Enter the day\'s exchange rate to convert this certificate to TZS.'),
            ]);
        }

        return $data;
    }

    protected function validateLines(Request $request): array
    {
        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.line_key' => 'required|string|max:100',
            'lines.*.label' => 'required|string|max:190',
            'lines.*.type' => 'required|in:' . implode(',', ProjectCertificateLine::types()),
            'lines.*.sort_order' => 'required|integer',
            'lines.*.operands' => 'nullable|array',
            'lines.*.value' => 'nullable|numeric',
        ]);

        return $data['lines'];
    }

    protected function saveLines(ProjectCertificate $certificate, array $lines): void
    {
        app(CertificateCalculationEngine::class)->validateAndOrder($lines);

        foreach ($lines as $line) {
            ProjectCertificateLine::create([
                'certificate_id' => $certificate->id,
                'line_key' => $line['line_key'],
                'label' => $line['label'],
                'type' => $line['type'],
                'sort_order' => $line['sort_order'],
                'operands' => $line['operands'] ?? null,
                'value' => $line['value'] ?? 0,
            ]);
        }
    }
}
