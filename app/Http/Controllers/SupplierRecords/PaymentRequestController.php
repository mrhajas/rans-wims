<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Http\Resources\SupplierRecords\SupplierRecordsCollection;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\SupplierInvoice;
use App\Services\Signatories\Documents\PaymentRequestDocument;
use App\Services\Signatories\Documents\Support\RefusesWithoutSignatures;
use App\Services\Signatories\SignatureResolver;
use App\Services\SupplierRecordsNotifier;
use App\Support\SupplierRecords\Ledger;
use App\Support\SupplierRecords\RequestStamps;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PaymentRequestController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    protected function modulePermissionSlug(): string
    {
        return 'payment-requests';
    }

    public const STATUSES = ['Pending Approval', 'Approved', 'Rejected', 'Paid'];

    public function index(Request $request)
    {
        $status = $request->query('status');

        $requests = PaymentRequest::query()
            ->filter(['status' => $status])
            ->orderByDesc('date_requested')
            ->paginate()
            ->withQueryString()
            ->through(function (PaymentRequest $r) {
                $data = $r->toArray();
                $data['supplier_name'] = $this->supplierName($r->supplier_id);

                return $data;
            });

        $agg = PaymentRequest::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as pending', [PaymentRequest::STATUS_PENDING_APPROVAL])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as approved', [PaymentRequest::STATUS_APPROVED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as rejected', [PaymentRequest::STATUS_REJECTED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as paid', [PaymentRequest::STATUS_PAID])
            ->first();

        return Inertia::render('SupplierRecords/PaymentRequest/Index', [
            'stats' => [
                'total' => (int) $agg->total,
                'pending' => (int) $agg->pending,
                'approved' => (int) $agg->approved,
                'rejected' => (int) $agg->rejected,
                'paid' => (int) $agg->paid,
            ],
            'filters' => ['status' => $status],
            'statuses' => self::STATUSES,
            'paymentRequests' => new SupplierRecordsCollection($requests),
        ]);
    }

    public function create(Request $request)
    {
        $invoiceId = $request->query('invoice_id');
        $invoice = $invoiceId ? SupplierInvoice::find((int) $invoiceId) : null;

        $invoiceData = null;
        if ($invoice) {
            $invoiceData = $invoice->toArray();
            $invoiceData['supplier_name'] = $this->supplierName($invoice->supplier_id);
            $invoiceData['balance'] = $invoice->balance;
        }

        $payableInvoices = SupplierInvoice::whereColumn('amount_paid', '<', 'total')
            ->get()
            ->map(function (SupplierInvoice $i) {
                $data = $i->toArray();
                $data['supplier_name'] = $this->supplierName($i->supplier_id);
                $data['balance'] = $i->balance;

                return $data;
            })
            ->values();

        return Inertia::render('SupplierRecords/PaymentRequest/Form', [
            'invoice' => $invoiceData,
            'payableInvoices' => $payableInvoices,
            'suppliersWithBalance' => $this->suppliersWithBalance(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:invoice,balance',
            'invoice_id' => 'required_if:type,invoice|nullable|integer',
            'supplier_id' => 'required_if:type,balance|nullable|integer',
            'amount' => 'required|numeric|min:0.01',
            'date_requested' => 'required|date',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $invoice = null;

        if ($data['type'] === PaymentRequest::TYPE_INVOICE) {
            $invoice = SupplierInvoice::find($data['invoice_id']);
            abort_unless($invoice, 404);
            $balance = $invoice->balance;
            abort_if($data['amount'] > $balance + 0.01, 422, 'Requested amount exceeds the invoice balance.');
            $supplierId = $invoice->supplier_id;
            $warehouseId = $invoice->warehouse_id;
        } else {
            $supplierId = $data['supplier_id'];
            $balance = $this->supplierBalance($supplierId);
            abort_if($data['amount'] > $balance + 0.01, 422, 'Requested amount exceeds the supplier\'s outstanding balance.');
            $warehouseId = null;
        }

        $pr = PaymentRequest::create([
            'reference' => $this->nextReference(),
            'type' => $data['type'],
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'requested_by_user_id' => $request->user()->id,
            'date_requested' => $data['date_requested'],
            'amount' => $data['amount'],
            'status' => PaymentRequest::STATUS_PENDING_APPROVAL,
            'approval_remarks' => $data['remarks'] ?? null,
        ] + RequestStamps::submitted((int) $request->user()->id));

        if ($invoice) {
            $pr->invoices()->attach($invoice->id);
        }

        RequestStamps::log($pr, 'Payment Request', 'submitted', null, PaymentRequest::STATUS_PENDING_APPROVAL);

        SupplierRecordsNotifier::paymentRequest('submitted', $pr, $request->user());

        return redirect()->route('supplier-records.payment-requests.show', $pr->id)->with('message', __('Payment Request submitted.'));
    }

    /**
     * Suppliers with a positive ledger balance — not just unpaid invoices,
     * so a "balance" Payment Request can also cover a purchase-without-
     * invoice obligation or an opening balance (Ledger is the financial
     * master; invoices are only one of several things that can owe money).
     */
    private function suppliersWithBalance(): array
    {
        return \App\Models\SupplierLedgerEntry::query()
            ->selectRaw('supplier_id, direction, SUM(amount) as total')
            ->groupBy('supplier_id', 'direction')
            ->get()
            ->groupBy('supplier_id')
            ->map(fn ($rows, $supplierId) => [
                'id' => (int) $supplierId,
                'name' => $this->supplierName((int) $supplierId),
                'balance' => round(
                    (float) ($rows->firstWhere('direction', 'debit')->total ?? 0)
                    - (float) ($rows->firstWhere('direction', 'credit')->total ?? 0),
                    2
                ),
            ])
            ->filter(fn ($s) => $s['balance'] > 0.01)
            ->values()
            ->all();
    }

    private function supplierBalance(int $supplierId): float
    {
        return Ledger::balance($supplierId);
    }

    public function show($id)
    {
        $pr = PaymentRequest::with('invoices')->find((int) $id);
        abort_unless($pr, 404);

        $data = $pr->toArray();
        $data['supplier_name'] = $this->supplierName($pr->supplier_id);
        $data['warehouse_name'] = $pr->warehouse_id ? $pr->warehouse?->name : null;

        $payments = Payment::where('payment_request_id', $pr->id)->get(['id', 'reference', 'paid_on', 'method', 'amount']);

        return Inertia::render('SupplierRecords/PaymentRequest/Show', ['paymentRequest' => $data, 'payments' => $payments]);
    }

    /**
     * A Payment Request has no separate Draft state — store() submits it
     * straight to Pending Approval — so "editable" here means the same
     * thing "not yet acted on" means everywhere else in Supplier Records:
     * only while still Pending Approval, before Approve/Reject/Paid has
     * happened. type/invoice_id/supplier_id stay fixed (changing what a
     * request is *for* is really a new request); only the figures a
     * requester might have mistyped — amount, date, remarks — are editable,
     * re-validated against the same live balance check store() applies.
     */
    public function edit($id)
    {
        $pr = PaymentRequest::with('invoices')->find((int) $id);
        abort_unless($pr, 404);
        abort_unless($pr->status === PaymentRequest::STATUS_PENDING_APPROVAL, 422, 'Only a Pending Approval payment request can be edited.');

        $data = $pr->toArray();
        $data['supplier_name'] = $this->supplierName($pr->supplier_id);

        if ($pr->type === PaymentRequest::TYPE_INVOICE) {
            $invoice = $pr->invoices->first();
            $data['invoice_reference'] = $invoice?->reference;
            $data['available_balance'] = $invoice ? $invoice->balance : null;
        } else {
            $data['available_balance'] = $this->supplierBalance($pr->supplier_id);
        }

        return Inertia::render('SupplierRecords/PaymentRequest/Form', [
            'edit' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $pr = PaymentRequest::with('invoices')->find((int) $id);
        abort_unless($pr, 404);
        abort_unless($pr->status === PaymentRequest::STATUS_PENDING_APPROVAL, 422, 'Only a Pending Approval payment request can be edited.');

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'date_requested' => 'required|date',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if ($pr->type === PaymentRequest::TYPE_INVOICE) {
            $invoice = $pr->invoices->first();
            $balance = $invoice ? $invoice->balance : 0;
            abort_if($data['amount'] > $balance + 0.01, 422, 'Requested amount exceeds the invoice balance.');
        } else {
            $balance = $this->supplierBalance($pr->supplier_id);
            abort_if($data['amount'] > $balance + 0.01, 422, 'Requested amount exceeds the supplier\'s outstanding balance.');
        }

        $pr->update([
            'amount' => $data['amount'],
            'date_requested' => $data['date_requested'],
            'approval_remarks' => $data['remarks'] ?? $pr->approval_remarks,
        ]);

        \App\Services\SupplierRecordsNotifier::record('payment_request_updated', $pr->fresh(), $request->user(), 'Payment Request', 'edited');

        return redirect()->route('supplier-records.payment-requests.show', $pr->id)->with('message', __('Payment Request updated.'));
    }

    public function destroy($id)
    {
        $paymentRequest = PaymentRequest::findOrFail($id);
        abort_if(
            in_array($paymentRequest->status, [PaymentRequest::STATUS_APPROVED, PaymentRequest::STATUS_PAID], true),
            422,
            'An approved or paid payment request cannot be deleted.'
        );

        $paymentRequest->delete();
        \App\Services\SupplierRecordsNotifier::record('payment_request_deleted', $paymentRequest, auth()->user(), 'Payment Request', 'deleted');

        return redirect()->route('supplier-records.payment-requests.index')->with('message', __('Payment Request removed.'));
    }

    public function approve(Request $request, $id)
    {
        $this->authorize('approve-payment-requests');

        $data = $request->validate(['approval_remarks' => 'nullable|string|max:1000']);
        $pr = PaymentRequest::find((int) $id);
        abort_unless($pr && $pr->status === PaymentRequest::STATUS_PENDING_APPROVAL, 422);
        // Signatories: when the document setup requires a signature and blocks finalising, refuse the approval without it (nothing changes with the default setup).
        if ($blocked = RefusesWithoutSignatures::message('payment_request', app(PaymentRequestDocument::class)->asIfApproved($pr, (int) $request->user()->id), __('approved'))) {
            return back()->with('error', $blocked);
        }
        $from = $pr->status;
        DB::transaction(function () use ($pr, $data, $request, $from) {
            $pr->update(['status' => PaymentRequest::STATUS_APPROVED, 'approval_remarks' => $data['approval_remarks'] ?? $pr->approval_remarks] + RequestStamps::approved((int) $request->user()->id));
            RequestStamps::log($pr, 'Payment Request', 'approved', $from, PaymentRequest::STATUS_APPROVED, $data['approval_remarks'] ?? null);
        });
        SupplierRecordsNotifier::paymentRequest('approved', $pr->fresh(), $request->user());

        return back()->with('message', __('Payment Request approved.'));
    }

    public function reject(Request $request, $id)
    {
        $this->authorize('approve-payment-requests');

        $data = $request->validate(['approval_remarks' => 'required|string|max:1000']);
        $pr = PaymentRequest::find((int) $id);
        abort_unless($pr && $pr->status === PaymentRequest::STATUS_PENDING_APPROVAL, 422);
        $from = $pr->status;
        DB::transaction(function () use ($pr, $data, $request, $from) {
            $pr->update(['status' => PaymentRequest::STATUS_REJECTED, 'approval_remarks' => $data['approval_remarks']] + RequestStamps::rejected((int) $request->user()->id));
            RequestStamps::log($pr, 'Payment Request', 'rejected', $from, PaymentRequest::STATUS_REJECTED, $data['approval_remarks']);
        });
        SupplierRecordsNotifier::paymentRequest('rejected', $pr->fresh(), $request->user());

        return back()->with('message', __('Payment Request rejected.'));
    }

    public function print($id)
    {
        $this->authorize('print-payment-requests');
        $pr = $this->printable($id);

        return view('supplier-records.payment-request-print', $this->printData($pr, false));
    }

    public function pdf($id)
    {
        $this->authorize('print-payment-requests');
        $pr = $this->printable($id);

        return Pdf::loadView('supplier-records.payment-request-print', $this->printData($pr, true))->download('payment-request-' . $pr->reference . '.pdf');
    }

    private function printable($id): PaymentRequest
    {
        $pr = PaymentRequest::with('invoices')->find((int) $id);
        abort_unless($pr, 404);

        return $pr;
    }

    private function printData(PaymentRequest $pr, bool $isPdf): array
    {
        $name = fn ($uid) => $uid ? \App\Models\User::query()->whereKey($uid)->value('name') : null;
        $standing = in_array($pr->status, [PaymentRequest::STATUS_APPROVED, PaymentRequest::STATUS_PAID], true);
        $payments = Payment::where('payment_request_id', $pr->id)->orderBy('paid_on')->get(['reference', 'paid_on', 'method', 'amount']);
        $amount = (float) $pr->amount;

        return [
            'req' => [
                'reference' => $pr->reference,
                'status' => $pr->status,
                'type_label' => $pr->type === PaymentRequest::TYPE_BALANCE ? 'Account Balance Settlement' : 'Invoice Payment',
                'supplier_name' => $this->supplierName($pr->supplier_id),
                'warehouse_name' => $pr->warehouse_id ? $pr->warehouse?->name : null,
                'date_requested' => optional($pr->date_requested)->format('d M Y'),
                'requested_by' => $name($pr->requested_by_user_id),
                'requested_at' => optional($pr->created_at)->format('d M Y H:i'),
                'amount' => $amount,
                'amount_in_words' => \App\Support\NumberToWords::convert($amount),
                'remarks' => $pr->approval_remarks,
                'decision' => $standing && $pr->approved_at ? ['label' => 'Approved', 'by' => $name($pr->approved_by_user_id), 'at' => $pr->approved_at->format('d M Y H:i')]
                    : ($pr->status === PaymentRequest::STATUS_REJECTED && $pr->rejected_at ? ['label' => 'Rejected', 'by' => $name($pr->rejected_by_user_id), 'at' => $pr->rejected_at->format('d M Y H:i')] : null),
                'invoices' => $pr->invoices->map(fn ($i) => [
                    'number' => $i->invoice_number ?: $i->reference, 'invoice_date' => $i->invoice_date ? \Illuminate\Support\Carbon::parse($i->invoice_date)->format('d M Y') : null, 'total' => (float) $i->total, 'balance' => (float) $i->balance,
                ])->values()->all(),
                'payments' => $payments->map(fn ($p) => ['reference' => $p->reference, 'paid_on' => $p->paid_on ? \Illuminate\Support\Carbon::parse($p->paid_on)->format('d M Y') : null, 'method' => $p->method, 'amount' => (float) $p->amount])->values()->all(),
                'paid_total' => (float) $payments->sum('amount'),
            ],
            'companyName' => \App\Models\PurchaseOrder::COMPANY_NAME,
            'qr' => \App\Support\PrintQrCode::dataUri(route('supplier-records.payment-requests.show', $pr->id)),
            'isPdf' => $isPdf,
            'pdfUrl' => route('supplier-records.payment-requests.pdf', $pr->id),
            'signatures' => app(SignatureResolver::class)->resolve('payment_request', $pr),
        ];
    }

    private function nextReference(): string
    {
        $maxSuffix = (int) DB::table('payment_requests')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix')
            ->where('reference', 'like', 'PYR-%')
            ->value('max_suffix');

        return 'PYR-' . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }
}
