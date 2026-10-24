<?php

namespace App\Http\Controllers;

use App\Models\ProjectCertificate;
use App\Models\ProjectCertificateContract;
use App\Models\ProjectCertificatePayment;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tecdiary\Laravel\Attachments\Attachment;

class ProjectCertificatePaymentController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    /**
     * `paid_currency`/`paid_amount` are what was actually received;
     * `amount` (the figure every total/outstanding/status calculation
     * uses) is always the CERTIFICATE-currency equivalent — computed here
     * server-side as paid_amount × exchange_rate, never trusted from the
     * client, so the totals stay correct even if a client-side conversion
     * display glitches. Certificate currency, not contract currency: a
     * certificate can be issued in a different currency than its contract
     * (Builder.vue's currency picker), and net_payment_amount/outstanding
     * are always in the CERTIFICATE's own currency — comparing a
     * contract-currency payment amount against it would silently corrupt
     * outstanding() whenever the two currencies differ.
     */
    public function store(Request $request, ProjectCertificate $certificate)
    {
        if ($certificate->status !== ProjectCertificate::STATUS_ISSUED) {
            return back()->withErrors(['status' => __('Payments can only be recorded against an issued certificate.')]);
        }
        $this->authorize('recordPayment', $certificate);
        $certificate->loadMissing('contract');

        $certificateCurrency = $certificate->effectiveCurrency();

        $data = $request->validate([
            'paid_currency' => 'required|string|max:10',
            'paid_amount' => 'required|numeric|min:0.01',
            'exchange_rate' => 'nullable|numeric|min:0.000001',
            'payment_date' => 'required|date',
            'method' => 'nullable|string|max:100',
            'paid_by' => 'nullable|string|max:190',
            'reference_number' => 'nullable|string|max:190',
            'notes' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,jpeg,png,pdf,doc,docx,xlsx'),
            // Overspend isn't blocked outright (spec req. 59: "require
            // confirmation or block according to permissions") — the
            // frontend confirms with the user first; `confirmed` records
            // that the warning was acknowledged rather than silently
            // allowing it.
            'confirmed' => 'nullable|boolean',
        ]);

        $isForeign = strtoupper($data['paid_currency']) !== strtoupper($certificateCurrency);
        if ($isForeign && empty($data['exchange_rate'])) {
            return back()->withErrors(['exchange_rate' => __('Enter the exchange rate to convert this payment to :currency.', ['currency' => $certificateCurrency])]);
        }
        $exchangeRate = $isForeign ? (float) $data['exchange_rate'] : 1.0;
        // Rounded to 2dp before multiplying, and that same rounded value is
        // what gets persisted as paid_amount below — otherwise an
        // unrounded input (validation only requires numeric|min:0.01, no
        // decimal-place cap) multiplies out to an `amount` a few cents
        // different from what "paid_amount × exchange_rate" shows on
        // screen, since paid_amount itself gets truncated to the
        // decimal(18,2) column on save regardless.
        $paidAmount = round((float) $data['paid_amount'], 2);
        $amount = round($paidAmount * $exchangeRate, 2);

        $outstanding = $certificate->outstanding();
        if ($amount > $outstanding && ! ($data['confirmed'] ?? false)) {
            return back()->withErrors(['paid_amount' => __('This payment (:amount) exceeds the outstanding balance (:outstanding). Confirm to proceed anyway.', [
                'amount' => $certificateCurrency . ' ' . number_format($amount, 2), 'outstanding' => $certificateCurrency . ' ' . number_format($outstanding, 2),
            ])]);
        }

        // `reference` is not user-supplied — ProjectCertificatePayment::reference()
        // computes RCT-{project code}-{seq} fresh on every read, so nothing
        // needs to be stamped here at all.
        $payment = ProjectCertificatePayment::create([
            'certificate_id' => $certificate->id,
            'amount' => $amount,
            'paid_currency' => $isForeign ? strtoupper($data['paid_currency']) : null,
            'paid_amount' => $isForeign ? $paidAmount : null,
            'exchange_rate' => $isForeign ? $exchangeRate : null,
            'payment_date' => $data['payment_date'],
            'method' => $data['method'] ?? null,
            'paid_by' => $data['paid_by'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        if ($request->hasFile('attachments')) {
            $payment->saveAttachments($request->file('attachments'), 'proof-of-payment');
        }

        log_activity(
            'Project Certificate Payment Recorded',
            ['certificate_number' => $certificate->certificate_number, 'amount' => $payment->amount, 'new_status' => $certificate->fresh()->paymentStatus()],
            $payment,
            'Project Certificate'
        );

        // Project Certificates workflow page (Who Gets Notified / Messages)
        \App\Services\Workflow\WorkflowMessenger::dispatch('certificates', 'payment_recorded', $certificate, $request->user(), [
            'account_id' => $request->user()->account_id, 'owner_user_id' => (int) $certificate->created_by, 'title' => __('Payment Recorded'),
            'message' => $certificate->reference . ': ' . number_format((float) $payment->amount, 0) . ' ' . ($certificate->currency ?: 'TZS') . '.', 'amount' => $payment->amount,
        ]);

        return back()->with('message', __('Payment recorded.'));
    }

    /**
     * Correcting an already-recorded payment — gated to Super Admin only
     * (ProjectCertificatePolicy::editPayment) and, at the route level, by
     * Laravel's password.confirm middleware as a second, independent check
     * behind the frontend's own inline password re-confirmation
     * (SprConfirmPassword.vue) — belt and suspenders for editing a
     * financial record other people may already be relying on.
     */
    public function update(Request $request, ProjectCertificate $certificate, ProjectCertificatePayment $payment)
    {
        if ($certificate->status !== ProjectCertificate::STATUS_ISSUED) {
            return back()->withErrors(['status' => __('Payments can only be edited on an issued certificate.')]);
        }
        $this->authorize('editPayment', $certificate);
        abort_unless($payment->certificate_id === $certificate->id, 404);

        $certificate->loadMissing('contract');
        $certificateCurrency = $certificate->effectiveCurrency();

        $data = $request->validate([
            'paid_currency' => 'required|string|max:10',
            'paid_amount' => 'required|numeric|min:0.01',
            'exchange_rate' => 'nullable|numeric|min:0.000001',
            'payment_date' => 'required|date',
            'method' => 'nullable|string|max:100',
            'paid_by' => 'nullable|string|max:190',
            'reference_number' => 'nullable|string|max:190',
            'notes' => 'nullable|string|max:1000',
            'confirmed' => 'nullable|boolean',
        ]);

        $isForeign = strtoupper($data['paid_currency']) !== strtoupper($certificateCurrency);
        if ($isForeign && empty($data['exchange_rate'])) {
            return back()->withErrors(['exchange_rate' => __('Enter the exchange rate to convert this payment to :currency.', ['currency' => $certificateCurrency])]);
        }
        $exchangeRate = $isForeign ? (float) $data['exchange_rate'] : 1.0;
        $paidAmount = round((float) $data['paid_amount'], 2);
        $amount = round($paidAmount * $exchangeRate, 2);

        // Outstanding balance recomputed EXCLUDING this payment's current
        // amount — otherwise editing a payment back to its own existing
        // value would always look like it's "exceeding" an outstanding
        // figure that already has this same payment subtracted from it.
        $otherPaid = (float) $certificate->payments()->where('id', '!=', $payment->id)->sum('amount');
        $outstanding = round((float) $certificate->net_payment_amount - $otherPaid, 2);
        if ($amount > $outstanding && ! ($data['confirmed'] ?? false)) {
            return back()->withErrors(['paid_amount' => __('This payment (:amount) exceeds the outstanding balance (:outstanding). Confirm to proceed anyway.', [
                'amount' => $certificateCurrency . ' ' . number_format($amount, 2), 'outstanding' => $certificateCurrency . ' ' . number_format($outstanding, 2),
            ])]);
        }

        $payment->update([
            'amount' => $amount,
            'paid_currency' => $isForeign ? strtoupper($data['paid_currency']) : null,
            'paid_amount' => $isForeign ? $paidAmount : null,
            'exchange_rate' => $isForeign ? $exchangeRate : null,
            'payment_date' => $data['payment_date'],
            'method' => $data['method'] ?? null,
            'paid_by' => $data['paid_by'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $payment->notes,
        ]);

        log_activity(
            'Project Certificate Payment Edited',
            ['certificate_number' => $certificate->certificate_number, 'payment_id' => $payment->id, 'new_amount' => $payment->amount, 'new_status' => $certificate->fresh()->paymentStatus()],
            $payment,
            'Project Certificate'
        );

        \App\Services\CertificateNotifier::certificate('payment_updated', $certificate->fresh(), $request->user(), __('Payment Corrected'), $certificate->reference . ': a payment of ' . number_format((float) $payment->amount, 0) . ' ' . ($certificate->currency ?: 'TZS') . ' was corrected by ' . $request->user()->name . '.', ['amount' => $payment->amount]);

        return back()->with('message', __('Payment updated.'));
    }

    /**
     * store() lets a payment carry proof-of-payment attachments
     * ('proof-of-payment' group) but until now nothing could retrieve one
     * back — write-only evidence in a module whose whole point is an
     * auditable payment trail. Gated by viewFinancials, same ability that
     * already protects Payment History/Unpaid Register, since a proof of
     * payment IS financial evidence.
     */
    public function downloadAttachment(Request $request, ProjectCertificate $certificate, ProjectCertificatePayment $payment, Attachment $attachment)
    {
        $this->authorize('viewFinancials', $certificate);
        abort_unless($payment->certificate_id === $certificate->id, 404);
        abort_unless((int) $attachment->model_id === $payment->id && $attachment->model_type === ProjectCertificatePayment::class, 404);

        $name = $attachment->title ?: $attachment->filename;

        return Storage::disk($attachment->disk)->download($attachment->filepath, $name);
    }

    /**
     * Removing proof-of-payment evidence is gated the same as correcting
     * the payment itself (editPayment — Super Admin only, see that
     * Policy method's docblock) since deleting evidence is at least as
     * sensitive as editing the amount it supports.
     */
    public function destroyAttachment(Request $request, ProjectCertificate $certificate, ProjectCertificatePayment $payment, Attachment $attachment)
    {
        $this->authorize('editPayment', $certificate);
        abort_unless($payment->certificate_id === $certificate->id, 404);
        abort_unless((int) $attachment->model_id === $payment->id && $attachment->model_type === ProjectCertificatePayment::class, 404);

        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        log_activity('Project Certificate Payment Attachment Removed', ['certificate_number' => $certificate->certificate_number, 'payment_id' => $payment->id, 'filename' => $attachment->filename], $payment, 'Project Certificate');

        return back()->with('message', __('Attachment removed.'));
    }

    public function overrideStatus(Request $request, ProjectCertificate $certificate)
    {
        // Was 'recordPayment' — the same ordinary, workflow-step-configurable
        // ability used to record a normal payment, so any role granted that
        // step (not just Super Admin) could flip a certificate's payment
        // status company-wide. 'editPayment' is the Super-Admin-only ability
        // used everywhere else exceptional corrections happen in this
        // module; the route now also requires password.confirm to match.
        $this->authorize('editPayment', $certificate);

        $data = $request->validate([
            'payment_status_override' => 'nullable|in:unpaid,partially_paid,paid_in_full,overdue,disputed,on_hold,cancelled',
            'reason' => 'required_with:payment_status_override|string|max:500',
        ]);

        $certificate->update([
            'payment_status_override' => $data['payment_status_override'] ?? null,
            'status_override_reason' => $data['payment_status_override'] ? $data['reason'] : null,
        ]);

        log_activity(
            'Project Certificate Payment Status Overridden',
            ['certificate_number' => $certificate->certificate_number, 'status' => $data['payment_status_override'] ?? 'auto', 'reason' => $data['reason'] ?? null],
            $certificate,
            'Project Certificate'
        );

        \App\Services\CertificateNotifier::certificate('payment_status_overridden', $certificate->fresh(), $request->user(), __('Payment Status Overridden'), $certificate->reference . ': ' . ($data['payment_status_override'] ? 'payment status set to ' . str_replace('_', ' ', $data['payment_status_override']) . ' by ' : 'payment status override cleared by ') . $request->user()->name . '.', ['remarks' => (string) ($data['reason'] ?? '')]);

        return back()->with('message', __('Payment status updated.'));
    }

    /**
     * The org-wide "All Payment History" page — every payment across every
     * project/certificate, one flat searchable/filterable register (vs.
     * Show.vue's per-certificate Payment History, which only ever sees one
     * certificate's own payments).
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->can('view-financials-project-certificates'), 403);

        return $this->paymentHistoryResponse($request);
    }

    /**
     * The same "All Payment History" page, pre-scoped to one project — the
     * Payment History button on that project's certificate list. Reuses
     * every bit of index()'s filtering/sorting/stats/export machinery; the
     * only difference is warehouse_id is forced rather than merely offered
     * as a filter, and the Vue page is told which project so it can lock
     * that dropdown and adjust its own title.
     */
    public function projectIndex(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('view-financials-project-certificates'), 403);

        return $this->paymentHistoryResponse($request, $warehouse);
    }

    protected function paymentHistoryResponse(Request $request, ?Warehouse $lockedWarehouse = null)
    {
        $filters = $request->only(['search', 'warehouse_id', 'client_id', 'currency', 'method', 'payment_status', 'date_from', 'date_to']);
        if ($lockedWarehouse) {
            $filters['warehouse_id'] = $lockedWarehouse->id;
        }

        $sort = $request->query('sort', 'payment_date');
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $filtered = $this->filteredPaymentsQuery($filters)->orderByDesc('payment_date')->orderByDesc('id')->get();
        $filtered = $this->applySearchFilter($filtered, $filters['search'] ?? null);
        // A certificate's own currency (effectiveCurrency()) overrides its
        // contract's — filtered in PHP, not SQL, for the same reason
        // payment_status is below: it depends on a computed accessor, not a
        // plain column a whereHas() can reach.
        if (! empty($filters['currency'])) {
            $filtered = $filtered->filter(fn (ProjectCertificatePayment $p) => strtoupper($p->certificate->effectiveCurrency()) === strtoupper($filters['currency']))->values();
        }
        if (! empty($filters['payment_status'])) {
            $filtered = $filtered->filter(fn (ProjectCertificatePayment $p) => $p->certificate->paymentStatus() === $filters['payment_status'])->values();
        }

        $stats = ['total_count' => $filtered->count(), 'total_amount' => [], 'this_month_amount' => []];
        // Keyed by warehouse_id so the "Projects Paid" card's tooltip can
        // list each project by name with its own payment count/total,
        // instead of just a bare number.
        $projectBreakdown = [];
        foreach ($filtered as $p) {
            // payment.amount is stored in the certificate's own currency
            // (effectiveCurrency()), not the contract's — see store()'s
            // docblock above.
            $currency = $p->certificate->effectiveCurrency();
            $stats['total_amount'][$currency] = round(($stats['total_amount'][$currency] ?? 0) + (float) $p->amount, 2);
            if ($p->payment_date && $p->payment_date->isCurrentMonth()) {
                $stats['this_month_amount'][$currency] = round(($stats['this_month_amount'][$currency] ?? 0) + (float) $p->amount, 2);
            }
            $warehouseId = $p->certificate->warehouse_id;
            if (! isset($projectBreakdown[$warehouseId])) {
                $projectBreakdown[$warehouseId] = [
                    'name' => $p->certificate->warehouse?->name,
                    'code' => $p->certificate->warehouse?->code,
                    'payments_count' => 0,
                    'total_amount' => [],
                ];
            }
            $projectBreakdown[$warehouseId]['payments_count']++;
            $projectBreakdown[$warehouseId]['total_amount'][$currency] = round(($projectBreakdown[$warehouseId]['total_amount'][$currency] ?? 0) + (float) $p->amount, 2);
        }
        $stats['unique_projects'] = count($projectBreakdown);
        $stats['projects'] = array_values($projectBreakdown);

        // Rows are built (and sorted) BEFORE pagination, not just the
        // current page's slice — every sortable column (Project, Client,
        // Certificate, Amount…) is a computed/related value, not a raw DB
        // column a SQL ORDER BY could reach, so there's no way to know
        // which rows belong on which page without sorting the full set
        // first. $filtered is already fully loaded into memory above (the
        // search filter needs that too), so this adds no extra queries.
        $rows = $filtered->map(fn (ProjectCertificatePayment $p) => $this->paymentRow($p))->values();
        $rows = $this->sortRows($rows, $sort, $direction);

        $page = (int) $request->query('page', 1);
        $perPage = 25;
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        $payments = paginated_response($paginator);

        return \Inertia\Inertia::render('ProjectCertificates/PaymentHistory', [
            'payments' => $payments,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'stats' => $stats,
            'lockedWarehouse' => $lockedWarehouse?->only(['id', 'name']),
            'projectStats' => $lockedWarehouse ? $this->projectStats($lockedWarehouse) : null,
            // "Main Warehouse" is a storage/stock hub, not a client project
            // — excluded from this filter dropdown for the same reason
            // ProjectCertificateController's project-picker lists exclude it.
            'warehouses' => Warehouse::ofAccount()->active()->visible()
                ->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])
                ->orderBy('name')->get(['id', 'name']),
            'clients' => \App\Models\Contact::where('category', 'Clients')->orderBy('name')->get(['id', 'name']),
            'currencies' => \App\Support\SupplierRecords\Currencies::all(),
            'methods' => ProjectCertificatePayment::whereNotNull('method')->distinct()->orderBy('method')->pluck('method'),
            'paymentStatuses' => ['unpaid', 'partially_paid', 'paid_in_full', 'overdue', 'disputed', 'on_hold', 'cancelled'],
        ]);
    }

    /**
     * The 4 project-level facts the scoped page's stat cards show —
     * deliberately UNaffected by whatever search/date/method filter is
     * currently active on the payment list below (those change "Total
     * Paid" in $stats above; these are the project's standing regardless
     * of that list's current filter).
     */
    protected function projectStats(Warehouse $warehouse): array
    {
        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->first();
        $certificates = ProjectCertificate::where('warehouse_id', $warehouse->id)->orderByDesc('certificate_number')->get();
        $issuedCertificates = $certificates->where('status', ProjectCertificate::STATUS_ISSUED);
        // Wider than $issuedCertificates — a certificate reopened for
        // revision (status back to draft, version bumped) still owes
        // whatever's left from when it WAS issued, so it keeps counting
        // toward Outstanding here. Kept consistent with the Unpaid
        // register and ProjectCertificateController's own project stats
        // (see ProjectCertificate::countsAsOutstanding()'s docblock).
        $outstandingCertificates = $certificates->filter(fn (ProjectCertificate $c) => $c->countsAsOutstanding());

        $outstanding = [];
        foreach ($outstandingCertificates as $c) {
            $amount = max(0, $c->outstanding());
            if ($amount > 0) {
                $currency = $c->effectiveCurrency();
                $outstanding[$currency] = round(($outstanding[$currency] ?? 0) + $amount, 2);
            }
        }

        // Total Certified — gross value formally certified so far, issued
        // certificates only (mirrors ProjectCertificateController's own
        // "Total Certified" figure): a certificate under revision hasn't
        // settled a final certified value yet, so it's excluded here even
        // though its old balance still counts toward Outstanding above.
        $totalCertified = [];
        foreach ($issuedCertificates as $c) {
            $currency = $c->effectiveCurrency();
            $totalCertified[$currency] = round(($totalCertified[$currency] ?? 0) + (float) $c->cumulative_certified_amount, 2);
        }
        $totalCertified = array_filter($totalCertified, fn ($v) => $v > 0);

        return [
            'contract_value' => $contract ? (float) $contract->revised_contract_sum : 0,
            'contract_currency' => $contract->currency ?? 'TZS',
            'certificates_count' => $certificates->count(),
            'total_certified' => $totalCertified,
            'outstanding' => $outstanding,
            // Backing list for the Certificates card's tooltip — reference
            // and status only, cheap to pass down whole rather than
            // round-tripping for a hover.
            'certificates' => $certificates->map(fn (ProjectCertificate $c) => [
                'reference' => $c->reference,
                'status' => $c->status,
            ])->values(),
        ];
    }

    /**
     * Every sortable column maps to a plain field on the already-built row
     * array (see paymentRow()) — amount sorts numerically, everything else
     * case-insensitively as text.
     */
    protected function sortRows($rows, string $sort, string $direction)
    {
        $sortable = ['payment_date', 'reference', 'warehouse_name', 'client_name', 'certificate_reference', 'method', 'paid_by', 'amount', 'recorded_by_name'];
        if (! in_array($sort, $sortable, true)) {
            $sort = 'payment_date';
        }

        return $rows->sortBy(function ($row) use ($sort) {
            $value = $row[$sort] ?? null;
            if ($value === null || $value === '') {
                return $sort === 'amount' ? 0 : '';
            }

            return $sort === 'amount' ? (float) $value : mb_strtolower((string) $value);
        }, SORT_REGULAR, $direction === 'desc')->values();
    }

    public function print(Request $request)
    {
        abort_unless($request->user()->can('view-financials-project-certificates'), 403);

        $data = $this->paymentsExportPayload($request);
        log_activity('Project Certificate Payment History Printed', ['count' => count($data['rows'])], $request->user(), 'Project Certificate');

        return view('reports.project-certificate-payments-list', $data + ['autoPrint' => true]);
    }

    public function download(Request $request)
    {
        abort_unless($request->user()->can('view-financials-project-certificates'), 403);

        $data = $this->paymentsExportPayload($request);
        log_activity('Project Certificate Payment History Downloaded', ['count' => count($data['rows'])], $request->user(), 'Project Certificate');

        return Pdf::loadView('reports.project-certificate-payments-list', $data)
            ->setPaper('a4', 'landscape')
            ->download('project-certificate-payments-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function paymentsExportPayload(Request $request): array
    {
        $filters = $request->only(['search', 'warehouse_id', 'client_id', 'currency', 'method', 'payment_status', 'date_from', 'date_to']);
        $payments = $this->filteredPaymentsQuery($filters)->orderByDesc('payment_date')->orderByDesc('id')->limit(2000)->get();
        $payments = $this->applySearchFilter($payments, $filters['search'] ?? null);
        if (! empty($filters['currency'])) {
            $payments = $payments->filter(fn (ProjectCertificatePayment $p) => strtoupper($p->certificate->effectiveCurrency()) === strtoupper($filters['currency']))->values();
        }
        if (! empty($filters['payment_status'])) {
            $payments = $payments->filter(fn (ProjectCertificatePayment $p) => $p->certificate->paymentStatus() === $filters['payment_status'])->values();
        }

        // A single-project filter drives the same project-scoped summary
        // cards (Contract Value/Certificates/Outstanding) the on-screen
        // page shows when opened from that project's own Payment History
        // button — reusing the warehouse_id filter rather than a separate
        // route, so this stays the same "Print"/"Download" link either way.
        $lockedWarehouse = ! empty($filters['warehouse_id']) ? Warehouse::find($filters['warehouse_id']) : null;

        $rows = $payments->map(fn (ProjectCertificatePayment $p) => $this->paymentRow($p))->values()->all();
        $totalAmount = [];
        foreach ($rows as $r) {
            $totalAmount[$r['currency']] = round(($totalAmount[$r['currency']] ?? 0) + $r['amount'], 2);
        }

        return [
            'title' => $lockedWarehouse ? 'Payment History · '.$lockedWarehouse->name : 'Project Certificates · Payment History',
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'rows' => $rows,
            'totalAmount' => $totalAmount,
            'lockedWarehouse' => $lockedWarehouse?->only(['id', 'name', 'code']),
            'projectStats' => $lockedWarehouse ? $this->projectStats($lockedWarehouse) : null,
        ];
    }

    protected function filteredPaymentsQuery(array $filters)
    {
        return ProjectCertificatePayment::query()
            ->with(['certificate.warehouse:id,name,code', 'certificate.contract.client:id,name', 'recordedBy:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->whereHas('certificate', fn ($c) => $c->where('warehouse_id', $v)))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->whereHas('certificate.contract', fn ($c) => $c->where('client_contact_id', $v)))
            ->when($filters['method'] ?? null, fn ($q, $v) => $q->where('method', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('payment_date', '<=', $v));
    }

    /**
     * Runs the free-text search in PHP rather than SQL — certificate
     * reference and payment reference are both computed accessors, not DB
     * columns (see ProjectCertificate::reference() / ProjectCertificatePayment::
     * reference(), which recompute from the project's CURRENT code on every
     * read rather than trusting a stale stored value), so a SQL LIKE can't
     * reach them. Every other searchable field is matched the same way here
     * for one consistent set of "what counts as a match" rules.
     */
    protected function applySearchFilter($payments, ?string $search)
    {
        if (! $search) {
            return $payments;
        }

        return $payments->filter(function (ProjectCertificatePayment $p) use ($search) {
            $certificate = $p->certificate;
            $haystacks = [
                $p->reference,
                $p->reference_number,
                $certificate?->reference,
                $certificate?->warehouse?->name,
                $certificate?->warehouse?->code,
                $certificate?->contract?->client?->name,
                $p->method,
                $p->paid_by,
            ];

            foreach ($haystacks as $value) {
                if ($value && stripos($value, $search) !== false) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    protected function paymentRow(ProjectCertificatePayment $p): array
    {
        $certificate = $p->certificate;
        $currency = $certificate->effectiveCurrency();

        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'reference_number' => $p->reference_number,
            'payment_date' => optional($p->payment_date)->toDateString(),
            'amount' => (float) $p->amount,
            'currency' => $currency,
            'method' => $p->method,
            'paid_by' => $p->paid_by,
            'paid_currency' => $p->paid_currency,
            'paid_amount' => $p->paid_amount !== null ? (float) $p->paid_amount : null,
            'exchange_rate' => $p->exchange_rate !== null ? (float) $p->exchange_rate : null,
            'notes' => $p->notes,
            'certificate_id' => $certificate->id,
            'certificate_reference' => $certificate->reference,
            'warehouse_id' => $certificate->warehouse_id,
            'warehouse_name' => $certificate->warehouse?->name,
            'client_name' => $certificate->contract?->client?->name,
            'recorded_by_name' => $p->recordedBy?->name,
            'payment_status' => $certificate->paymentStatus(),
        ];
    }
}
