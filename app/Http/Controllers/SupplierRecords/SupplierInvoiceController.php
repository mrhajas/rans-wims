<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Http\Resources\SupplierRecords\SupplierRecordsCollection;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Services\Signatories\Documents\StockSigningGuard;
use App\Support\PrintQrCode;
use App\Support\SupplierRecords\Ledger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class SupplierInvoiceController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    protected function modulePermissionSlug(): string
    {
        return 'supplier-invoices';
    }

    public const STATUSES = ['Pending Match', 'Matched', 'Approved', 'Partially Paid', 'Paid', 'Disputed'];

    public function index(Request $request)
    {
        $status = $request->query('status');
        $supplierId = $request->query('supplier_id');

        $invoices = SupplierInvoice::query()
            ->with('purchaseOrder:id,currency')
            ->filter(['status' => $status, 'supplier_id' => $supplierId])
            ->orderByDesc('invoice_date')
            ->paginate()
            ->withQueryString()
            ->through(function (SupplierInvoice $i) {
                $data = $i->toArray();
                $data['supplier_name'] = $this->supplierName($i->supplier_id);
                $data['balance'] = $i->balance;
                $data['currency'] = $i->purchaseOrder->currency ?? null;
                $data['created_by_name'] = $this->userName($i->created_by);

                return $data;
            });

        $agg = SupplierInvoice::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN total > amount_paid THEN total - amount_paid ELSE 0 END), 0) as outstanding')
            ->selectRaw('COALESCE(SUM(CASE WHEN total > amount_paid AND due_date < ? THEN 1 ELSE 0 END), 0) as overdue', [now()->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as paid', [SupplierInvoice::STATUS_PAID])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as disputed', [SupplierInvoice::STATUS_DISPUTED])
            ->first();

        return Inertia::render('SupplierRecords/SupplierInvoice/Index', [
            'stats' => [
                'total' => (int) $agg->total,
                'outstanding' => round((float) $agg->outstanding, 2),
                'overdue' => (int) $agg->overdue,
                'paid' => (int) $agg->paid,
                'disputed' => (int) $agg->disputed,
            ],
            'filters' => ['status' => $status, 'supplier_id' => $supplierId],
            'statuses' => self::STATUSES,
            'suppliers' => $this->supplierOptions(),
            'invoices' => new SupplierRecordsCollection($invoices),
        ]);
    }

    public function create(Request $request)
    {
        $poId = $request->query('purchase_order_id');

        return Inertia::render('SupplierRecords/SupplierInvoice/Form', [
            'invoice' => null,
            'purchaseOrder' => $poId ? PurchaseOrder::with('items')->find((int) $poId) : null,
            'purchaseOrders' => PurchaseOrder::whereIn('status', ['Sent to Supplier', 'Acknowledged', 'Partially Delivered', 'Completed'])
                ->whereDoesntHave('supplierInvoices')
                ->get(['id', 'reference', 'supplier_id'])
                ->map(fn ($o) => ['id' => $o->id, 'reference' => $o->reference, 'supplier_id' => $o->supplier_id, 'supplier_name' => $this->supplierName($o->supplier_id)])
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_order_id' => 'required|integer',
            'invoice_number' => 'required|string|max:100',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date',
            'remarks' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . env('ATTACHMENT_EXTS', 'jpg,png,pdf,docx,xlsx,zip'),
        ]);

        $invoice = DB::transaction(function () use ($request, $data) {
        // Lock the PO row so two concurrent submissions can't both pass the
        // one-invoice-per-PO check below and create duplicate invoices.
        $po = PurchaseOrder::with('items')->lockForUpdate()->find($data['purchase_order_id']);
        abort_unless($po, 404);
        abort_unless(
            in_array($po->status, ['Sent to Supplier', 'Acknowledged', 'Partially Delivered', 'Completed'], true),
            422,
            'An invoice can only be recorded against a Purchase Order that has been sent to the supplier.'
        );
        abort_if(
            SupplierInvoice::where('purchase_order_id', $po->id)->exists(),
            422,
            'A Supplier Invoice has already been recorded against this Purchase Order.'
        );

        $grn = GoodsReceipt::with('items')->where('purchase_order_id', $po->id)->first();

        // Bill for what was actually received where a GRN exists — capped at
        // what was ordered per line — rather than always the full PO amount
        // regardless of delivery. Falls back to the PO's own figures only
        // when no GRN has been recorded yet (a pro-forma/advance invoice).
        $receivedByItem = [];
        if ($grn) {
            foreach (GoodsReceipt::where('purchase_order_id', $po->id)->with('items')->get() as $g) {
                foreach ($g->items as $line) {
                    $key = $line->item_id ? 'id:' . $line->item_id : 'code:' . $line->item_code;
                    $receivedByItem[$key] = ($receivedByItem[$key] ?? 0) + (float) $line->received_quantity;
                }
            }
        }

        $orderedTotal = $po->items->sum('quantity');
        $receivedTotal = array_sum($receivedByItem);
        $matchStatus = ! $grn ? 'Unmatched' : ($receivedTotal >= $orderedTotal ? SupplierInvoice::STATUS_MATCHED : 'Quantity Variance');

        $lineItems = [];
        $subtotal = 0.0;
        foreach ($po->items as $poItem) {
            $key = $poItem->item_id ? 'id:' . $poItem->item_id : 'code:' . $poItem->item_code;
            $billQuantity = $grn ? min((float) $poItem->quantity, (float) ($receivedByItem[$key] ?? 0)) : (float) $poItem->quantity;
            $subtotal += $billQuantity * (float) $poItem->unit_price;

            $lineItems[] = [
                'item_id' => $poItem->item_id,
                'item_name' => $poItem->item_name,
                'item_code' => $poItem->item_code,
                'unit_label' => $poItem->unit_label,
                'quantity' => $billQuantity,
                'unit_price' => $poItem->unit_price,
            ];
        }
        $subtotal = round($subtotal, 2);
        // Same formula PurchaseOrderController uses to derive its own
        // tax_amount/total from subtotal + tax_percentage, applied here to
        // the (possibly smaller, receipt-capped) billed subtotal instead of
        // blindly copying the PO's own already-computed total.
        $taxAmount = $po->tax_included ? round($subtotal * (float) $po->tax_percentage / 100, 2) : 0;

        $invoice = SupplierInvoice::create([
            'reference' => $data['invoice_number'],
            'invoice_number' => $data['invoice_number'],
            'purchase_order_id' => $po->id,
            'goods_receipt_id' => $grn->id ?? null,
            'supplier_id' => $po->supplier_id,
            'warehouse_id' => $po->warehouse_id,
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'],
            'status' => SupplierInvoice::STATUS_PENDING_MATCH,
            'match_status' => $matchStatus,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => round($subtotal + $taxAmount, 2),
            'amount_paid' => 0,
            'remarks' => $data['remarks'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        foreach ($lineItems as $line) {
            $invoice->items()->create($line);
        }

        Ledger::recordInvoice($invoice);

        if ($request->hasFile('attachments')) {
            $invoice->saveAttachments($request->file('attachments'), 'supplier-invoice');
        }

        return $invoice;
        });

        log_activity(__('Supplier Invoice recorded.'), ['reference' => $invoice->reference, 'purchase_order_id' => $invoice->purchase_order_id, 'by_user_id' => $request->user()->id], $invoice, 'Supplier Invoice');
        \App\Services\SupplierRecordsNotifier::record('invoice_created', $invoice, $request->user(), 'Supplier Invoice', 'recorded');

        return redirect()->route('supplier-records.supplier-invoices.show', $invoice->id)->with('message', __('Supplier Invoice recorded.'));
    }

    public function show($id)
    {
        $invoice = SupplierInvoice::with(['items', 'purchaseOrder.purchaseRequest', 'purchaseOrder.quotation', 'goodsReceipt', 'attachments'])->find((int) $id);
        abort_unless($invoice, 404);

        $data = $invoice->toArray();
        $data['supplier_name'] = $this->supplierName($invoice->supplier_id);
        $data['balance'] = $invoice->balance;
        $data['created_by_name'] = $this->userName($invoice->created_by);
        $data['attachments'] = $invoice->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->title ?: $a->filename])->values();
        $data['trace'] = $invoice->purchaseOrder ? $this->traceForPurchaseOrder($invoice->purchaseOrder) : null;

        $payments = Payment::whereHas('allocations', fn ($q) => $q->where('supplier_invoice_id', $invoice->id))
            ->get(['id', 'reference', 'paid_on', 'method', 'amount']);

        return Inertia::render('SupplierRecords/SupplierInvoice/Show', ['invoice' => $data, 'payments' => $payments]);
    }

    public function edit(Request $request, $id)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403, 'Supplier invoices can only be edited by a Super Admin.');

        $invoice = SupplierInvoice::with(['items', 'attachments', 'purchaseOrder:id,reference,supplier_id,currency'])->findOrFail($id);

        return Inertia::render('SupplierRecords/SupplierInvoice/Form', [
            'invoice' => array_merge($invoice->toArray(), [
                'attachments' => $invoice->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->title ?: $a->filename])->values(),
            ]),
            'purchaseOrder' => $invoice->purchaseOrder,
            'purchaseOrders' => [],
        ]);
    }

    public function update(Request $request, $id)
    {
        $invoice = SupplierInvoice::findOrFail($id);

        // The Approve/Dispute quick-actions on the Show page post only
        // {status, remarks} — anything else is a Super-Admin full edit.
        if ($request->has('status') && ! $request->has('invoice_number')) {
            $this->authorize('approve-supplier-invoices');

            // Once any payment has been recorded (Partially Paid/Paid), this
            // quick-action can no longer flip the invoice to Approved/
            // Disputed — that would leave amount_paid > 0 next to a status
            // that contradicts its own payment history.
            abort_if(
                in_array($invoice->status, [SupplierInvoice::STATUS_PARTIALLY_PAID, SupplierInvoice::STATUS_PAID], true),
                422,
                'This invoice already has payments recorded against it and can no longer have its status changed this way.'
            );

            $data = $request->validate(['status' => 'required|in:Approved,Disputed', 'remarks' => 'nullable|string|max:1000']);

            // Signatories: when the document setup requires a signature and blocks finalising, refuse to approve without it (nothing changes with the default setup).
            if ($data['status'] === SupplierInvoice::STATUS_APPROVED
                && ($blocked = StockSigningGuard::refusal('supplier_invoice', $invoice, ['status' => SupplierInvoice::STATUS_APPROVED, 'approved_by_user_id' => $request->user()->id, 'approved_on' => now()], __('approved')))) {
                return back()->with('error', $blocked);
            }

            $from = $invoice->status;
            if ($data['status'] === SupplierInvoice::STATUS_APPROVED) {
                // the rows always describe the standing state: approving stamps the approver and drops any earlier dispute stamp
                $invoice->update($data + ['approved_by_user_id' => $request->user()->id, 'approved_on' => now(), 'disputed_by_user_id' => null, 'disputed_on' => null]);
            } else {
                // disputing takes the approval back: its stamp is cleared (the audit row below keeps who approved and when) and the frozen signature set is dropped
                $earlier = ['approved_by_user_id' => $invoice->approved_by_user_id, 'approved_on' => optional($invoice->approved_on)->toDateTimeString()];
                $invoice->update($data + ['disputed_by_user_id' => $request->user()->id, 'disputed_on' => now(), 'approved_by_user_id' => null, 'approved_on' => null]);
                StockSigningGuard::forget('supplier_invoice', (int) $invoice->id);
            }
            log_activity($data['status'] === SupplierInvoice::STATUS_APPROVED ? __('Supplier Invoice approved.') : __('Supplier Invoice disputed.'), [
                'reference' => $invoice->reference, 'from' => $from, 'to' => $data['status'], 'by_user_id' => $request->user()->id, 'remarks' => $data['remarks'] ?? null,
            ] + ($earlier ?? []), $invoice, 'Supplier Invoice');

            \App\Services\SupplierRecordsNotifier::record('invoice_updated', $invoice->fresh(), $request->user(), 'Supplier Invoice', strtolower($data['status']), (string) ($data['remarks'] ?? ''));

            return back()->with('message', __('Invoice status updated.'));
        }

        abort_unless($request->user()->hasRole('Super Admin'), 403, 'Supplier invoices can only be edited by a Super Admin.');

        $data = $request->validate([
            'invoice_number' => 'required|string|max:100',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date',
            'remarks' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . env('ATTACHMENT_EXTS', 'jpg,png,pdf,docx,xlsx,zip'),
        ]);

        $invoice->update([
            'invoice_number' => $data['invoice_number'],
            'reference' => $data['invoice_number'],
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'],
            'remarks' => $data['remarks'] ?? null,
        ]);

        Ledger::recordInvoice($invoice);

        if ($request->hasFile('attachments')) {
            $invoice->saveAttachments($request->file('attachments'), 'supplier-invoice');
        }

        log_activity(__('Supplier Invoice edited.'), ['reference' => $invoice->reference, 'edited_by_user_id' => $request->user()->id], $invoice, 'Supplier Invoice');
        \App\Services\SupplierRecordsNotifier::record('invoice_updated', $invoice->fresh(), $request->user(), 'Supplier Invoice', 'edited');

        return redirect()->route('supplier-records.supplier-invoices.show', $id)->with('message', __('Supplier Invoice updated.'));
    }

    public function print(Request $request, $id)
    {
        $this->authorize('print-supplier-invoices');

        return view('supplier-records.supplier-invoice-print', $this->printData($this->printable($id), false));
    }

    public function pdf(Request $request, $id)
    {
        $this->authorize('print-supplier-invoices');
        $invoice = $this->printable($id);

        return Pdf::loadView('supplier-records.supplier-invoice-print', $this->printData($invoice, true))->download('supplier-invoice-' . $invoice->reference . '.pdf');
    }

    private function printable($id): SupplierInvoice
    {
        return SupplierInvoice::with(['items', 'purchaseOrder', 'goodsReceipt'])->findOrFail((int) $id);
    }

    private function printData(SupplierInvoice $invoice, bool $isPdf): array
    {
        $po = $invoice->purchaseOrder;
        $supplier = $invoice->supplier;
        $payments = Payment::whereHas('allocations', fn ($q) => $q->where('supplier_invoice_id', $invoice->id))->with(['allocations' => fn ($q) => $q->where('supplier_invoice_id', $invoice->id)])->orderBy('paid_on')->get();

        return [
            'invoice' => [
                'id' => $invoice->id,
                'reference' => $invoice->reference,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'match_status' => $invoice->match_status,
                'invoice_date' => optional($invoice->invoice_date)->format('d M Y'),
                'due_date' => optional($invoice->due_date)->format('d M Y'),
                'currency' => $po->currency ?? 'TZS',
                'supplier_name' => $this->supplierName($invoice->supplier_id),
                'supplier_phone' => $supplier->phone ?? null,
                'supplier_tin' => $supplier ? (\App\Support\SupplierRecords\SupplierDetails::get($supplier)['tax_id'] ?? null) : null,
                'purchase_order_reference' => $po->reference ?? null,
                'goods_receipt_reference' => optional($invoice->goodsReceipt)->reference,
                'warehouse_name' => $invoice->warehouse_id ? optional(\App\Models\Warehouse::find($invoice->warehouse_id))->name : PurchaseOrder::COMPANY_NAME,
                'subtotal' => (float) $invoice->subtotal,
                'tax_amount' => (float) $invoice->tax_amount,
                'total' => (float) $invoice->total,
                'amount_paid' => (float) $invoice->amount_paid,
                'balance' => (float) $invoice->balance,
                'remarks' => $invoice->remarks,
                'prepared_by_name' => $this->userName($invoice->created_by),
                'created_at' => optional($invoice->created_at)->format('d M Y H:i'),
                'approved_by_name' => $this->userName($invoice->approved_by_user_id),
                'approved_on' => optional($invoice->approved_on)->format('d M Y H:i'),
                'items' => $invoice->items->map(fn ($l) => [
                    'item_name' => $l->item_name, 'item_code' => $l->item_code, 'unit_label' => $l->unit_label,
                    'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'line_total' => round((float) $l->quantity * (float) $l->unit_price, 2),
                ])->values()->all(),
                'payments' => $payments->map(fn ($p) => [
                    'reference' => $p->reference, 'paid_on' => optional($p->paid_on)->format('d M Y'), 'method' => $p->method,
                    'applied' => (float) $p->allocations->sum('amount'),
                ])->values()->all(),
            ],
            'companyName' => PurchaseOrder::COMPANY_NAME,
            'qr' => PrintQrCode::dataUri(route('supplier-records.supplier-invoices.show', $invoice->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('supplier_invoice', $invoice),
        ];
    }

    public function downloadAttachment(Request $request, $id, Attachment $attachment)
    {
        $this->authorize('read-supplier-invoices');
        abort_unless((int) $attachment->model_id === (int) $id && $attachment->model_type === SupplierInvoice::class, 404);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    public function destroy($id)
    {
        $invoice = SupplierInvoice::findOrFail($id);

        // A paid (or partially paid) invoice, or one with real payment
        // allocations against it, must not be deletable — its ledger debit
        // is the other half of those payment credits; removing it without
        // removing them would silently break the ledger's own arithmetic
        // and leave PaymentAllocation rows pointing at nothing.
        abort_if(
            (float) $invoice->amount_paid > 0 || $invoice->allocations()->exists(),
            422,
            'This Supplier Invoice has payments recorded against it and can no longer be deleted.'
        );

        \App\Models\SupplierLedgerEntry::where('source_type', SupplierInvoice::class)->where('source_id', (int) $id)->delete();
        $invoice->delete();
        \App\Services\SupplierRecordsNotifier::record('invoice_deleted', $invoice, auth()->user(), 'Supplier Invoice', 'deleted');

        return redirect()->route('supplier-records.supplier-invoices.index')->with('message', __('Supplier Invoice removed.'));
    }
}
