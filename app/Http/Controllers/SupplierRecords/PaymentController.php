<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Http\Resources\SupplierRecords\SupplierRecordsCollection;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\PurchaseOrder;
use App\Support\SupplierRecords\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class PaymentController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    protected function modulePermissionSlug(): string
    {
        return 'payments';
    }

    public const METHODS = ['Bank Transfer', 'Cheque', 'Cash', 'Mobile Money'];
    public const VERIFICATION_STATUSES = ['Pending Verification', 'Verified'];

    public function index(Request $request)
    {
        $payments = Payment::query()
            ->with(['supplier:id,name', 'paidBy:id,name'])
            ->when($request->query('supplier_id'), fn ($q, $id) => $q->where('supplier_id', $id))
            ->orderByDesc('paid_on')
            ->paginate()
            ->withQueryString()
            ->through(function (Payment $p) {
                $data = $p->toArray();
                $data['supplier_name'] = $p->supplier->name ?? null;
                $data['paid_by_name'] = $p->paidBy->name ?? null;

                return $data;
            });

        $agg = Payment::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_status = ? THEN 1 ELSE 0 END), 0) as verified', [Payment::VERIFICATION_VERIFIED])
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_status = ? THEN 1 ELSE 0 END), 0) as pending', [Payment::VERIFICATION_PENDING])
            ->first();

        return Inertia::render('SupplierRecords/Payment/Index', [
            'stats' => [
                'total' => (int) $agg->total,
                'amount' => round((float) $agg->amount, 2),
                'verified' => (int) $agg->verified,
                'pending' => (int) $agg->pending,
            ],
            'payments' => new SupplierRecordsCollection($payments),
        ]);
    }

    public function create(Request $request)
    {
        $paymentRequestId = $request->query('payment_request_id');
        $pr = $paymentRequestId ? PaymentRequest::find((int) $paymentRequestId) : null;

        $prData = null;
        if ($pr) {
            $prData = $pr->toArray();
            $prData['supplier_name'] = $this->supplierName($pr->supplier_id);
        }

        $approvedRequests = PaymentRequest::where('status', PaymentRequest::STATUS_APPROVED)
            ->get()
            ->map(function (PaymentRequest $r) {
                $data = $r->toArray();
                $data['supplier_name'] = $this->supplierName($r->supplier_id);

                return $data;
            })
            ->values();

        return Inertia::render('SupplierRecords/Payment/Form', [
            'paymentRequest' => $prData,
            'approvedRequests' => $approvedRequests,
            'methods' => self::METHODS,
            'currencies' => $this->currencyOptions(),
            'users' => $this->userOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'payment_request_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string|max:60',
            'currency' => 'nullable|string|max:10',
            'cheque_number' => 'required_if:method,Cheque|nullable|string|max:60',
            'reference_number' => 'nullable|string|max:100',
            'paid_on' => 'required|date',
            'paid_by_user_id' => 'required|integer',
            'remarks' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
        ]);

        $payment = DB::transaction(function () use ($request, $data) {
        // Lock the Payment Request row so two concurrent submissions can't both
        // read the same already-paid total and together exceed the approved amount.
        $pr = PaymentRequest::with('invoices')->lockForUpdate()->find($data['payment_request_id']);
        abort_unless($pr && $pr->status === PaymentRequest::STATUS_APPROVED, 422, 'The Payment Request must be Approved before recording a payment.');

        // The approved Payment Request amount is the ceiling — it was itself
        // already validated against the invoice/ledger balance at request
        // time, so re-checking against that same figure here is what stops
        // this endpoint from recording an arbitrary, unbounded amount.
        $alreadyPaid = (float) Payment::where('payment_request_id', $pr->id)->sum('amount');
        $remaining = round((float) $pr->amount - $alreadyPaid, 2);
        abort_if(
            round((float) $data['amount'], 2) > $remaining + 0.01,
            422,
            __('This payment (:amount) exceeds the approved Payment Request amount remaining (:remaining).', [
                'amount' => number_format($data['amount'], 2), 'remaining' => number_format(max($remaining, 0), 2),
            ])
        );

        $payment = Payment::create([
            'reference' => $this->nextReference(),
            'payment_request_id' => $pr->id,
            'supplier_id' => $pr->supplier_id,
            'amount' => $data['amount'],
            'method' => $data['method'],
            'currency' => $data['currency'] ?? 'TZS',
            'cheque_number' => $data['method'] === 'Cheque' ? $data['cheque_number'] : null,
            'reference_number' => $data['reference_number'] ?? null,
            'paid_on' => $data['paid_on'],
            'paid_by_user_id' => $data['paid_by_user_id'],
            'remarks' => $data['remarks'] ?? null,
            'verification_status' => Payment::VERIFICATION_PENDING,
            'verified_by_user_id' => null,
            'verified_on' => null,
        ]);

        if ($request->hasFile('attachments')) {
            $payment->saveAttachments($request->file('attachments'), 'proof-of-payment');
        }

        // Only mark the request Paid once the approved amount is fully covered;
        // a partial payment leaves it Approved so the remainder can still be paid.
        if (round($remaining - (float) $data['amount'], 2) <= 0.01) {
            $pr->update(['status' => PaymentRequest::STATUS_PAID]);
            \App\Support\SupplierRecords\RequestStamps::log($pr, 'Payment Request', 'marked paid', PaymentRequest::STATUS_APPROVED, PaymentRequest::STATUS_PAID);
        }

        if ($pr->type === PaymentRequest::TYPE_BALANCE) {
            $payment->allocateAgainstBalance($pr->supplier_id, $data['amount']);
        } elseif ($pr->type === PaymentRequest::TYPE_INVOICE) {
            $invoice = $pr->invoices->first();
            if ($invoice) {
                $payment->allocateAgainstInvoice($invoice, $data['amount']);
            }
        }

        // Ledger entry always reflects the full amount actually paid, even
        // when it exceeds what was allocated to invoices above — the excess
        // reduces the supplier's balance as a credit rather than vanishing.
        Ledger::recordPayment($payment);

        return $payment;
        });

        \App\Services\SupplierRecordsNotifier::record('payment_recorded', $payment, $request->user(), 'Payment', 'recorded');

        return redirect()->route('supplier-records.payments.show', $payment->id)->with('message', __('Payment recorded.'));
    }

    public function show($id)
    {
        $payment = Payment::with(['allocations.supplierInvoice', 'attachments'])->find((int) $id);
        abort_unless($payment, 404);

        $data = $payment->toArray();
        unset($data['allocations']);

        $data['supplier_name'] = $this->supplierName($payment->supplier_id);
        $data['paid_by_name'] = $this->userName($payment->paid_by_user_id);
        $data['verified_by_name'] = $this->userName($payment->verified_by_user_id);
        $data['applied_invoices'] = $payment->allocations->map(fn ($a) => [
            'id' => $a->supplierInvoice->id,
            'reference' => $a->supplierInvoice->reference,
            'applied_amount' => $a->amount,
        ])->values();
        $data['attachments'] = $payment->attachments->map(fn (Attachment $a) => [
            'id' => $a->id,
            'name' => $a->title ?: $a->filename,
        ])->values();

        return Inertia::render('SupplierRecords/Payment/Show', ['payment' => $data]);
    }

    public function verify(Request $request, $id)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403, 'Only directors can verify payments.');
        $payment = Payment::find((int) $id);
        abort_unless($payment, 404);
        abort_if($payment->verification_status === Payment::VERIFICATION_VERIFIED, 422, 'This payment is already verified.');
        // Signatories: refuse the final step when an administrator made a signature required and it is missing (inert until switched on).
        if ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('supplier_payment_voucher', $payment, [
            'verification_status' => Payment::VERIFICATION_VERIFIED, 'verified_by_user_id' => $request->user()->id, 'verified_on' => now(),
        ], __('verified'))) {
            return back()->with('error', $refusal);
        }
        $payment->update([
            'verification_status' => Payment::VERIFICATION_VERIFIED,
            'verified_by_user_id' => $request->user()->id,
            'verified_on' => now(),
        ]);

        \App\Services\SupplierRecordsNotifier::record('payment_verified', $payment->fresh(), $request->user(), 'Payment', 'verified');

        return back()->with('message', __('Payment verified.'));
    }

    public function downloadAttachment(Request $request, Payment $payment, Attachment $attachment)
    {
        $this->authorize('read-payments');
        if ((int) $attachment->model_id !== (int) $payment->id || $attachment->model_type !== Payment::class) {
            abort(404);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    public function voucher($id)
    {
        $this->authorize('read-payments');
        $payment = Payment::with(['allocations.supplierInvoice'])->find((int) $id);
        abort_unless($payment, 404);

        $supplier = Contact::find($payment->supplier_id);
        $paymentRequest = PaymentRequest::find($payment->payment_request_id);

        $paymentArray = $payment->toArray();
        unset($paymentArray['allocations']);
        $paymentArray['applied_invoices'] = $payment->allocations->map(fn ($a) => [
            'reference' => $a->supplierInvoice->reference,
            'invoice_date' => $a->supplierInvoice->invoice_date,
            'total' => $a->supplierInvoice->total,
            'applied_amount' => $a->amount,
        ])->values()->all();
        $paymentArray['verified_by_name'] = $this->userName($payment->verified_by_user_id);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('supplier-records.payment-voucher', [
            'payment' => $paymentArray,
            'supplier' => $supplier,
            'paymentRequest' => $paymentRequest ? $paymentRequest->toArray() : null,
            'companyName' => PurchaseOrder::COMPANY_NAME,
            'paidByName' => $this->userName($payment->paid_by_user_id),
            'qr' => \App\Support\PrintQrCode::dataUri(route('supplier-records.payments.show', $payment->id)),
            'isPdf' => true,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('supplier_payment_voucher', $payment),
        ]);

        return $pdf->stream('payment-voucher-' . $payment->reference . '.pdf');
    }

    private function nextReference(): string
    {
        $maxSuffix = (int) DB::table('payments')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix')
            ->where('reference', 'like', 'PMT-%')
            ->value('max_suffix');

        return 'PMT-' . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }
}
