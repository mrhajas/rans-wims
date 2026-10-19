<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Http\Resources\SupplierRecords\SupplierRecordsCollection;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\Signatories\Documents\PurchaseOrderDocument;
use App\Services\Signatories\Documents\Support\RefusesWithoutSignatures;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class PurchaseOrderController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    protected function modulePermissionSlug(): string
    {
        return 'purchase-orders';
    }

    public const STATUSES = [
        'Draft', 'Submitted', 'Approved', 'Sent to Supplier', 'Acknowledged',
        'Partially Delivered', 'Completed', 'Rejected', 'Cancelled',
    ];

    // Shown when a PO has no warehouse/project — i.e. it's for the company itself.
    public const COMPANY_NAME = 'RANS Company Limited';

    public function index(Request $request)
    {
        $status = $request->query('status');
        $supplierId = $request->query('supplier_id');
        $search = trim((string) $request->query('search', ''));

        $orders = PurchaseOrder::query()
            ->with(['supplier:id,name', 'warehouse:id,name'])
            ->filter(['status' => $status, 'supplier_id' => $supplierId, 'search' => $search !== '' ? $search : null])
            ->orderByDesc('date_issued')
            ->paginate()
            ->withQueryString()
            ->through(fn (PurchaseOrder $o) => $this->presentSummary($o));

        $counts = PurchaseOrder::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('SupplierRecords/PurchaseOrder/Index', [
            'filters' => ['status' => $status, 'supplier_id' => $supplierId, 'search' => $search],
            'stats' => [
                'total' => (int) $counts->sum(),
                'draft' => (int) ($counts[PurchaseOrder::STATUS_DRAFT] ?? 0),
                'awaiting_approval' => (int) ($counts[PurchaseOrder::STATUS_SUBMITTED] ?? 0),
                'in_progress' => (int) (($counts[PurchaseOrder::STATUS_SENT] ?? 0) + ($counts[PurchaseOrder::STATUS_ACKNOWLEDGED] ?? 0) + ($counts[PurchaseOrder::STATUS_PARTIALLY_DELIVERED] ?? 0)),
                'completed' => (int) ($counts[PurchaseOrder::STATUS_COMPLETED] ?? 0),
            ],
            'statuses' => self::STATUSES,
            'suppliers' => $this->supplierOptions(),
            'purchaseOrders' => new SupplierRecordsCollection($orders),
            'cards' => [
                'draft' => PurchaseOrder::where('status', PurchaseOrder::STATUS_DRAFT)->count(),
                'awaiting_approval' => PurchaseOrder::where('status', PurchaseOrder::STATUS_SUBMITTED)->count(),
                'in_progress' => PurchaseOrder::whereIn('status', [
                    PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_ACKNOWLEDGED, PurchaseOrder::STATUS_PARTIALLY_DELIVERED,
                ])->count(),
                'completed' => PurchaseOrder::where('status', PurchaseOrder::STATUS_COMPLETED)->count(),
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('SupplierRecords/PurchaseOrder/Form', [
            'purchaseOrder' => null,
            'suppliers' => $this->supplierOptions(),
            'warehouses' => $this->warehouseOptions(),
            'items' => $this->itemOptions(),
            'currencies' => $this->currencyOptions(),
            'companyName' => self::COMPANY_NAME,
            'purchaseRequests' => $this->approvedPurchaseRequestOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $order = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $order = new PurchaseOrder();
                $order->fill([
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'] ?? null,
                    'purchase_request_id' => $data['purchase_request_id'] ?? null,
                    'quotation_id' => $data['quotation_id'] ?? null,
                    'date_issued' => $data['date_issued'],
                    'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                    'currency' => $data['currency'],
                    'tax_included' => $data['tax_included'],
                    'tax_percentage' => $data['tax_percentage'],
                    'subtotal' => $data['subtotal'],
                    'tax_amount' => $data['tax_amount'],
                    'total' => $data['total'],
                    'terms' => $data['terms'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'status' => PurchaseOrder::STATUS_DRAFT,
                    'user_id' => $request->user()->id,
                    'submitted_on' => null,
                    'approved_by_user_id' => null,
                    'approved_on' => null,
                    'sent_on' => null,
                    'acknowledged_on' => null,
                    'cancelled_on' => null,
                    'approval_remarks' => null,
                    'reference' => $this->nextReference(),
                ]);
                $order->save();
                break;
            } catch (QueryException $e) {
                $isDuplicateReference = (string) $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'purchase_orders_reference_unique');

                if (! $isDuplicateReference || $attempt === 2) {
                    throw $e;
                }
                $order = null;
            }
        }

        if (! $order) {
            abort(500, 'Unable to generate a unique purchase order reference.');
        }

        foreach ($data['items'] as $item) {
            $order->items()->create($item);
        }

        if ($request->hasFile('attachments')) {
            $order->saveAttachments($request->file('attachments'), 'purchase-order');
        }

        log_activity(__('Purchase Order created.'), ['reference' => $order->reference, 'by_user_id' => $request->user()->id], $order, 'Purchase Order');
        \App\Services\SupplierRecordsNotifier::record('purchase_order_created', $order, $request->user(), 'Purchase Order', 'created');

        return redirect()->route('supplier-records.purchase-orders.show', $order->id)->with('message', __('Purchase Order created.'));
    }

    public function show($id)
    {
        $order = PurchaseOrder::with(['items', 'supplier:id,name', 'warehouse:id,name', 'attachments'])->findOrFail($id);

        $goodsReceipts = $order->goodsReceipts()->get(['id', 'reference', 'status', 'date_received'])->values();
        $invoices = $order->supplierInvoices()->get(['id', 'reference', 'status', 'total', 'invoice_date'])->values();

        return Inertia::render('SupplierRecords/PurchaseOrder/Show', [
            'purchaseOrder' => $this->presentDetail($order),
            'goodsReceipts' => $goodsReceipts,
            'invoices' => $invoices,
            'companyName' => self::COMPANY_NAME,
        ]);
    }

    public function edit(Request $request, $id)
    {
        $order = PurchaseOrder::with(['items', 'attachments'])->findOrFail($id);
        abort_unless($order->status === PurchaseOrder::STATUS_DRAFT || $request->user()->hasRole('Super Admin'), 422, 'Only Draft purchase orders can be edited.');
        $hasDownstreamRecords = $order->status !== PurchaseOrder::STATUS_DRAFT
            && ($order->goodsReceipts()->exists() || $order->supplierInvoices()->exists());
        abort_if($hasDownstreamRecords, 422, 'This Purchase Order already has Goods Receipts or Supplier Invoices recorded against it, its items and pricing can no longer be changed.');

        return Inertia::render('SupplierRecords/PurchaseOrder/Form', [
            'purchaseOrder' => $this->presentDetail($order),
            'suppliers' => $this->supplierOptions(),
            'warehouses' => $this->warehouseOptions(),
            'items' => $this->itemOptions(),
            'currencies' => $this->currencyOptions(),
            'companyName' => self::COMPANY_NAME,
            'purchaseRequests' => $this->approvedPurchaseRequestOptions(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $order = PurchaseOrder::findOrFail($id);
        abort_unless($order->status === PurchaseOrder::STATUS_DRAFT || $request->user()->hasRole('Super Admin'), 422, 'Only Draft purchase orders can be edited.');

        // Once a Goods Receipt or Supplier Invoice already exists against
        // this PO, they've snapshotted its items/pricing — even a Super
        // Admin correcting a non-Draft order past this point must not
        // silently rewrite the figures those downstream records depend on.
        $hasDownstreamRecords = $order->status !== PurchaseOrder::STATUS_DRAFT
            && ($order->goodsReceipts()->exists() || $order->supplierInvoices()->exists());
        abort_if($hasDownstreamRecords, 422, 'This Purchase Order already has Goods Receipts or Supplier Invoices recorded against it, its items and pricing can no longer be changed.');

        $data = $this->validated($request);

        // A Super Admin may edit a non-Draft order. The approval fields are kept as they are, but the content changed after the approval was
        // given: leave an explicit audit row (who approved and when, who edited, in which status) so the Signatories report can warn about it.
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            log_activity($order->approved_on ? PurchaseOrderDocument::EDITED_AFTER_APPROVAL : __('Purchase Order edited after submission.'), [
                'reference' => $order->reference, 'status' => $order->status, 'edited_by_user_id' => $request->user()->id,
                'approved_by_user_id' => $order->approved_by_user_id, 'approved_on' => optional($order->approved_on)->toDateTimeString(),
            ], $order, 'Purchase Order');
        }

        $order->update([
            'supplier_id' => $data['supplier_id'],
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'purchase_request_id' => $data['purchase_request_id'] ?? null,
            'quotation_id' => $data['quotation_id'] ?? null,
            'date_issued' => $data['date_issued'],
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'currency' => $data['currency'],
            'tax_included' => $data['tax_included'],
            'tax_percentage' => $data['tax_percentage'],
            'subtotal' => $data['subtotal'],
            'tax_amount' => $data['tax_amount'],
            'total' => $data['total'],
            'terms' => $data['terms'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]);

        if ($request->hasFile('attachments')) {
            $order->saveAttachments($request->file('attachments'), 'purchase-order');
        }

        $order->items()->delete();
        foreach ($data['items'] as $item) {
            $order->items()->create($item);
        }

        \App\Services\SupplierRecordsNotifier::record('purchase_order_updated', $order->fresh(), $request->user(), 'Purchase Order', 'edited');

        return redirect()->route('supplier-records.purchase-orders.show', $id)->with('message', __('Purchase Order updated.'));
    }

    public function destroy($id)
    {
        $order = PurchaseOrder::findOrFail($id);

        // Same downstream-records guard as edit()/update() — deleting is
        // irreversible, so it's held to at least the same bar as editing,
        // with no Super Admin carve-out: once a GRN or Invoice exists, that
        // financial trail must not be removable out from under them.
        abort_if(
            $order->goodsReceipts()->exists() || $order->supplierInvoices()->exists(),
            422,
            'This Purchase Order already has Goods Receipts or Supplier Invoices recorded against it and can no longer be deleted.'
        );

        $order->delete();
        \App\Services\SupplierRecordsNotifier::record('purchase_order_deleted', $order, auth()->user(), 'Purchase Order', 'deleted');

        return redirect()->route('supplier-records.purchase-orders.index')->with('message', __('Purchase Order removed.'));
    }

    public function submit(Request $request, $id)
    {
        $this->transition($id, PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SUBMITTED, [
            'submitted_on' => now(), 'submitted_by_user_id' => $request->user()->id,
        ], __('Purchase Order submitted for approval.'));

        $this->announce('purchase_order_submitted', $id, 'submitted for approval');

        return back()->with('message', __('Purchase Order submitted for approval.'));
    }

    public function approve(Request $request, $id)
    {
        $data = $request->validate(['approval_remarks' => 'nullable|string|max:1000']);
        $this->transition($id, PurchaseOrder::STATUS_SUBMITTED, PurchaseOrder::STATUS_APPROVED, [
            'approved_by_user_id' => $request->user()->id,
            'approved_on' => now(),
            'approval_remarks' => $data['approval_remarks'] ?? null,
        ], __('Purchase Order approved.'));

        $this->announce('purchase_order_approved', $id, 'approved', (string) ($data['approval_remarks'] ?? ''));

        return back()->with('message', __('Purchase Order approved.'));
    }

    public function send(Request $request, $id)
    {
        // Signatories: when the document setup requires a signature and blocks finalising, refuse to issue the order without it (nothing changes with the default setup).
        $this->authorize('approve-purchase-orders');
        $current = PurchaseOrder::findOrFail($id);
        if ($current->status === PurchaseOrder::STATUS_APPROVED
            && ($blocked = RefusesWithoutSignatures::message('purchase_order', app(PurchaseOrderDocument::class)->asIfSent($current, (int) $request->user()->id), __('sent to the supplier')))) {
            return back()->with('error', $blocked);
        }

        $this->transition($id, PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_SENT, [
            'sent_on' => now(), 'sent_by_user_id' => $request->user()->id,
        ], __('Purchase Order marked as sent to the supplier.'));

        $this->announce('purchase_order_sent', $id, 'marked as sent to the supplier');

        return back()->with('message', __('Purchase Order marked as sent to the supplier.'));
    }

    public function acknowledge(Request $request, $id)
    {
        $this->transition($id, PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_ACKNOWLEDGED, [
            'acknowledged_on' => now(), 'acknowledged_by_user_id' => $request->user()->id,
        ], __('Purchase Order acknowledged by the supplier.'));

        $this->announce('purchase_order_acknowledged', $id, 'acknowledged by the supplier');

        return back()->with('message', __('Supplier acknowledgement recorded.'));
    }

    public function reject(Request $request, $id)
    {
        $data = $request->validate(['approval_remarks' => 'required|string|max:1000']);
        $this->transition($id, PurchaseOrder::STATUS_SUBMITTED, PurchaseOrder::STATUS_REJECTED, [
            'approval_remarks' => $data['approval_remarks'], 'rejected_by_user_id' => $request->user()->id, 'rejected_on' => now(),
        ], __('Purchase Order rejected.'));

        $this->announce('purchase_order_rejected', $id, 'rejected', (string) $data['approval_remarks']);

        return back()->with('message', __('Purchase Order rejected.'));
    }

    public function cancel(Request $request, $id)
    {
        $this->authorize('approve-purchase-orders');

        $order = PurchaseOrder::findOrFail($id);
        abort_if(in_array($order->status, [PurchaseOrder::STATUS_COMPLETED, PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_REJECTED], true), 422, 'This purchase order can no longer be cancelled.');

        $from = $order->status;
        $order->update(['status' => PurchaseOrder::STATUS_CANCELLED, 'cancelled_on' => now(), 'cancelled_by_user_id' => $request->user()->id]);
        log_activity(__('Purchase Order cancelled.'), ['reference' => $order->reference, 'from' => $from, 'to' => PurchaseOrder::STATUS_CANCELLED, 'by_user_id' => $request->user()->id], $order, 'Purchase Order');

        \App\Services\SupplierRecordsNotifier::record('purchase_order_cancelled', $order->fresh(), $request->user(), 'Purchase Order', 'cancelled');

        return back()->with('message', __('Purchase Order cancelled.'));
    }

    public function print(Request $request, $id)
    {
        $this->authorize('print-purchase-orders');

        return view('supplier-records.purchase-order-print', $this->printData($this->printable($id), false));
    }

    public function pdf(Request $request, $id)
    {
        $this->authorize('print-purchase-orders');
        $order = $this->printable($id);

        return Pdf::loadView('supplier-records.purchase-order-print', $this->printData($order, true))->download('purchase-order-' . $order->reference . '.pdf');
    }

    private function printable($id): PurchaseOrder
    {
        return PurchaseOrder::with(['items', 'supplier', 'warehouse:id,name', 'purchaseRequest', 'quotation'])->findOrFail($id);
    }

    private function printData(PurchaseOrder $order, bool $isPdf): array
    {
        $supplier = $order->supplier;
        $details = $supplier ? \App\Support\SupplierRecords\SupplierDetails::get($supplier) : [];
        $stage = fn (?string $label, $userId, $at) => ['label' => $label, 'by' => $this->userName($userId ? (int) $userId : null), 'at' => $at ? $at->format('d M Y H:i') : null];

        return [
            'order' => array_merge($this->presentDetail($order), [
                'supplier_phone' => $supplier->phone ?? null,
                'supplier_email' => $supplier->email ?? null,
                'supplier_tin' => $details['tax_id'] ?? null,
                'prepared_by_name' => $this->userName($order->user_id),
                'created_at' => optional($order->created_at)->format('d M Y H:i'),
                'stages' => array_values(array_filter([
                    $order->submitted_on ? $stage(__('Submitted for approval'), $order->submitted_by_user_id, $order->submitted_on) : null,
                    $order->approved_on ? $stage(__('Approved'), $order->approved_by_user_id, $order->approved_on) : null,
                    $order->sent_on ? $stage(__('Sent to supplier'), $order->sent_by_user_id, $order->sent_on) : null,
                    $order->acknowledged_on ? $stage(__('Acknowledged by supplier'), $order->acknowledged_by_user_id, $order->acknowledged_on) : null,
                    $order->rejected_on ? $stage(__('Rejected'), $order->rejected_by_user_id, $order->rejected_on) : null,
                    $order->cancelled_on ? $stage(__('Cancelled'), $order->cancelled_by_user_id, $order->cancelled_on) : null,
                ])),
            ]),
            'companyName' => self::COMPANY_NAME,
            'qr' => PrintQrCode::dataUri(route('supplier-records.purchase-orders.show', $order->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('purchase_order', $order),
        ];
    }

    public function downloadAttachment(Request $request, $id, Attachment $attachment)
    {
        $this->authorize('read-purchase-orders');
        abort_unless((int) $attachment->model_id === (int) $id && $attachment->model_type === PurchaseOrder::class, 404);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    private function announce(string $event, $id, string $action, string $remarks = ''): void
    {
        \App\Services\SupplierRecordsNotifier::record($event, PurchaseOrder::find((int) $id), auth()->user(), 'Purchase Order', $action, $remarks);
    }

    /** Moves the order one step and stamps WHO did it and WHEN (the $extra columns), with an explicit audit row naming the causer. */
    private function transition(int $id, string $from, string $to, array $extra = [], ?string $logText = null): void
    {
        $this->authorize('approve-purchase-orders');

        $order = PurchaseOrder::findOrFail($id);
        abort_unless($order->status === $from, 422, "This purchase order must be {$from} for this action.");

        $order->update(array_merge(['status' => $to], $extra));
        log_activity($logText ?: __('Purchase Order status changed.'), ['reference' => $order->reference, 'from' => $from, 'to' => $to, 'by_user_id' => auth()->id()], $order, 'Purchase Order');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer',
            'scope' => 'required|in:project,company',
            'warehouse_id' => 'nullable|required_if:scope,project|integer',
            'purchase_request_id' => 'nullable|integer',
            'quotation_id' => 'nullable|integer',
            'date_issued' => 'required|date',
            'expected_delivery_date' => 'nullable|date',
            'currency' => 'nullable|string|max:10',
            'tax_included' => 'required|boolean',
            'tax_percentage' => 'required_if:tax_included,1|nullable|numeric|min:0|max:100',
            'terms' => 'nullable|string|max:500',
            'remarks' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'required|string|max:190',
            'items.*.item_code' => 'nullable|string|max:60',
            'items.*.unit_label' => 'nullable|string|max:30',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . env('ATTACHMENT_EXTS', 'jpg,png,pdf,docx,xlsx,zip'),
        ]);

        if ($data['scope'] === 'company') {
            $data['warehouse_id'] = null;
        }
        unset($data['scope']);

        $subtotal = collect($data['items'])->sum(fn ($l) => $l['quantity'] * $l['unit_price']);
        $taxPercentage = $data['tax_included'] ? (float) $data['tax_percentage'] : 0;
        $taxAmount = $data['tax_included'] ? round($subtotal * $taxPercentage / 100, 2) : 0;

        $data['subtotal'] = $subtotal;
        $data['tax_percentage'] = $taxPercentage;
        $data['tax_amount'] = $taxAmount;
        $data['total'] = round($subtotal + $taxAmount, 2);
        $data['currency'] = $data['currency'] ?? 'TZS';

        return $data;
    }

    private function nextReference(): string
    {
        $maxSuffix = (int) \Illuminate\Support\Facades\DB::table('purchase_orders')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)) as max_suffix')
            ->where('reference', 'like', 'PO-%')
            ->value('max_suffix');

        return 'PO-' . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    private function approvedPurchaseRequestOptions()
    {
        return PurchaseRequest::where('status', PurchaseRequest::STATUS_APPROVED)->get(['id', 'reference'])->values();
    }

    private function warehouseName(PurchaseOrder $order): ?string
    {
        return $order->warehouse_id ? $order->warehouse?->name : self::COMPANY_NAME;
    }

    private function presentSummary(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'supplier_id' => $order->supplier_id,
            'supplier_name' => $order->supplier?->name,
            'warehouse_id' => $order->warehouse_id,
            'warehouse_name' => $this->warehouseName($order),
            'date_issued' => optional($order->date_issued)->format('Y-m-d'),
            'currency' => $order->currency,
            'total' => (float) $order->total,
            'status' => $order->status,
            'user_id' => $order->user_id,
            'user_name' => $this->userName($order->user_id),
        ];
    }

    private function presentDetail(PurchaseOrder $order): array
    {
        return array_merge($this->presentSummary($order), [
            'purchase_request_id' => $order->purchase_request_id,
            'purchase_request_reference' => $order->purchaseRequest?->reference,
            'quotation_id' => $order->quotation_id,
            'quotation_reference' => $order->quotation?->reference,
            'expected_delivery_date' => optional($order->expected_delivery_date)->format('Y-m-d'),
            'currency' => $order->currency,
            'tax_included' => (bool) $order->tax_included,
            'tax_percentage' => (float) $order->tax_percentage,
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'terms' => $order->terms,
            'remarks' => $order->remarks,
            'approval_remarks' => $order->approval_remarks,
            'submitted_on' => optional($order->submitted_on)->toIso8601String(),
            'approved_by_user_id' => $order->approved_by_user_id,
            'approved_by_name' => $this->userName($order->approved_by_user_id),
            'approved_on' => optional($order->approved_on)->toIso8601String(),
            'sent_on' => optional($order->sent_on)->toIso8601String(),
            'acknowledged_on' => optional($order->acknowledged_on)->toIso8601String(),
            'cancelled_on' => optional($order->cancelled_on)->toIso8601String(),
            'attachments' => $order->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->title ?: $a->filename])->values(),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'item_id' => $item->item_id,
                'item_name' => $item->item_name,
                'item_code' => $item->item_code,
                'unit_label' => $item->unit_label,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'received_quantity' => (float) $item->received_quantity,
            ])->values(),
        ]);
    }
}
