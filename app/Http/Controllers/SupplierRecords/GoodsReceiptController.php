<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Http\Resources\SupplierRecords\SupplierRecordsCollection;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Services\Signatories\Documents\GoodsReceiptDocument;
use App\Services\Signatories\Documents\Support\RefusesWithoutSignatures;
use App\Support\PrintQrCode;
use App\Support\SupplierRecords\Ledger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class GoodsReceiptController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    protected function modulePermissionSlug(): string
    {
        return 'goods-receipts';
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $receipts = GoodsReceipt::query()
            ->with('purchaseOrder:id,reference')
            ->when($search !== '', fn ($q) => $q->where('reference', 'like', '%' . $search . '%'))
            ->orderByDesc('date_received')
            ->paginate()
            ->withQueryString()
            ->through(function (GoodsReceipt $r) {
                $data = $r->toArray();
                $data['purchase_order_reference'] = $r->purchaseOrder->reference ?? null;
                $data['warehouse_name'] = $this->warehouseLabel($r->warehouse_id);
                $data['received_by_name'] = $this->userName($r->received_by_user_id);

                return $data;
            });

        $agg = GoodsReceipt::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as confirmed', [GoodsReceipt::STATUS_CONFIRMED])
            ->selectRaw('COALESCE(SUM(CASE WHEN date_received >= ? THEN 1 ELSE 0 END), 0) as this_month', [now()->startOfMonth()->toDateString()])
            ->selectRaw('COUNT(DISTINCT purchase_order_id) as orders')
            ->first();

        return Inertia::render('SupplierRecords/GoodsReceipt/Index', [
            'stats' => [
                'total' => (int) $agg->total,
                'confirmed' => (int) $agg->confirmed,
                'this_month' => (int) $agg->this_month,
                'orders' => (int) $agg->orders,
            ],
            'filters' => ['search' => $search],
            'goodsReceipts' => new SupplierRecordsCollection($receipts),
        ]);
    }

    public function create(Request $request)
    {
        $poId = $request->query('purchase_order_id');
        $purchaseOrder = $poId ? PurchaseOrder::with(['items', 'purchaseRequest', 'quotation'])->find((int) $poId) : null;

        return Inertia::render('SupplierRecords/GoodsReceipt/Form', [
            'goodsReceipt' => null,
            'purchaseOrder' => $purchaseOrder,
            'trace' => $purchaseOrder ? $this->traceForPurchaseOrder($purchaseOrder) : null,
            'items' => $this->itemOptions(),
            'purchaseOrders' => PurchaseOrder::whereIn('status', ['Sent to Supplier', 'Acknowledged', 'Partially Delivered'])
                ->get(['id', 'reference', 'supplier_id'])
                ->map(fn ($o) => ['id' => $o->id, 'reference' => $o->reference, 'supplier_name' => $this->supplierName($o->supplier_id)])
                ->values(),
        ]);
    }


    /** POs eligible to have goods received against them — same list used to populate create()'s dropdown. */
    private const RECEIVABLE_STATUSES = [
        PurchaseOrder::STATUS_SENT,
        PurchaseOrder::STATUS_ACKNOWLEDGED,
        PurchaseOrder::STATUS_PARTIALLY_DELIVERED,
    ];

    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_order_id' => 'required|integer',
            'date_received' => 'required|date',
            'remarks' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'required|string|max:190',
            'items.*.item_code' => 'nullable|string|max:60',
            'items.*.unit_label' => 'nullable|string|max:30',
            'items.*.received_quantity' => 'required|numeric|min:0',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
        ]);

        // Signatories: when the document setup requires a signature and blocks finalising, refuse to record the receipt without it (nothing changes with the default setup).
        if ($blocked = RefusesWithoutSignatures::message('goods_receipt', app(GoodsReceiptDocument::class)->asIfRecorded((int) $request->user()->id), __('recorded'))) {
            return back()->withInput()->with('error', $blocked);
        }

        $grn = DB::transaction(function () use ($request, $data) {
            $po = PurchaseOrder::whereKey($data['purchase_order_id'])->lockForUpdate()->first();
            abort_unless($po, 404);
            abort_unless(
                in_array($po->status, self::RECEIVABLE_STATUSES, true),
                422,
                __('Goods can only be received against a Purchase Order that has been sent to the supplier and is not yet fully delivered.')
            );

            $ledger = $this->purchaseOrderItemLedger($po);

            $grn = GoodsReceipt::create([
                'reference' => $this->nextReference(),
                'purchase_order_id' => $po->id,
                'warehouse_id' => $po->warehouse_id,
                'received_by_user_id' => $request->user()->id,
                'date_received' => $data['date_received'],
                'status' => GoodsReceipt::STATUS_CONFIRMED,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $items = $this->validateAndBuildReceiptLines($data['items'], $ledger);
            foreach ($items as $line) {
                $grn->items()->create($line);
            }

            $this->syncPurchaseOrderItemReceivedQuantities($po);

            $fullyReceived = $po->items()->get()->every(fn ($i) => (float) $i->received_quantity >= (float) $i->quantity - 0.0001);
            $anyReceived = $po->items()->get()->contains(fn ($i) => (float) $i->received_quantity > 0);
            $po->update([
                'status' => $fullyReceived ? PurchaseOrder::STATUS_COMPLETED : ($anyReceived ? PurchaseOrder::STATUS_PARTIALLY_DELIVERED : $po->status),
            ]);

            Ledger::recordObligation($po, $this->receivedValue($po), $grn);

            if ($request->hasFile('attachments')) {
                $grn->saveAttachments($request->file('attachments'), 'goods-receipt');
            }

            return $grn;
        });

        log_activity(__('Goods Receipt recorded.'), ['reference' => $grn->reference, 'purchase_order_id' => $grn->purchase_order_id, 'received_by_user_id' => $grn->received_by_user_id], $grn, 'Goods Receipt');
        \App\Services\SupplierRecordsNotifier::record('goods_receipt_created', $grn, $request->user(), 'Goods Receipt', 'recorded');

        return redirect()->route('supplier-records.goods-receipts.show', $grn->id)->with('message', __('Goods Receipt recorded.'));
    }

    /**
     * Cross-checks each submitted line against the PO's own item list —
     * the client's ordered_quantity is never trusted; the server derives it
     * from the PO itself and rejects any line that isn't actually on the PO
     * or whose received_quantity would exceed what's still outstanding.
     */
    private function validateAndBuildReceiptLines(array $lines, array $ledger): array
    {
        $out = [];
        foreach ($lines as $line) {
            $key = ! empty($line['item_id']) ? 'id:' . $line['item_id'] : 'code:' . ($line['item_code'] ?? '');
            abort_unless(isset($ledger[$key]), 422, __('":name" is not an item on this Purchase Order.', ['name' => $line['item_name']]));

            $entry = $ledger[$key];
            $remaining = round($entry['ordered'] - $entry['received'], 4);
            $requested = round((float) $line['received_quantity'], 4);
            abort_if(
                $requested > $remaining + 0.0001,
                422,
                __('Received quantity for ":name" (:requested) exceeds the remaining ordered quantity (:remaining).', [
                    'name' => $line['item_name'], 'requested' => $requested, 'remaining' => max($remaining, 0),
                ])
            );

            // Running total updated in-memory so multiple lines for the same
            // item within one submission are each checked against what's
            // truly left, not the same starting figure.
            $ledger[$key]['received'] += $requested;

            $out[] = [
                'item_id' => $line['item_id'] ?? null,
                'item_name' => $line['item_name'],
                'item_code' => $line['item_code'] ?? null,
                'unit_label' => $line['unit_label'] ?? null,
                'ordered_quantity' => $entry['ordered'],
                'received_quantity' => $requested,
            ];
        }

        return $out;
    }

    /**
     * True ordered/already-received quantities per PO item, keyed the same
     * way as receivedValue()'s price map. $excludingGrnId lets update()
     * compute "received by every OTHER GRN" before validating this GRN's
     * own new lines against that.
     */
    private function purchaseOrderItemLedger(PurchaseOrder $po, ?int $excludingGrnId = null): array
    {
        $ledger = [];
        foreach ($po->items()->get() as $poItem) {
            $key = $poItem->item_id ? 'id:' . $poItem->item_id : 'code:' . $poItem->item_code;
            $ledger[$key] = ['ordered' => (float) $poItem->quantity, 'received' => 0.0];
        }

        $query = GoodsReceipt::where('purchase_order_id', $po->id)->with('items');
        if ($excludingGrnId) {
            $query->where('id', '!=', $excludingGrnId);
        }
        foreach ($query->get() as $grn) {
            foreach ($grn->items as $line) {
                $key = $line->item_id ? 'id:' . $line->item_id : 'code:' . $line->item_code;
                if (isset($ledger[$key])) {
                    $ledger[$key]['received'] += (float) $line->received_quantity;
                }
            }
        }

        return $ledger;
    }

    /** Always live-recomputed from every GRN against this PO — never manually incremented, so it can't drift. */
    private function syncPurchaseOrderItemReceivedQuantities(PurchaseOrder $po): void
    {
        $totals = [];
        foreach (GoodsReceipt::where('purchase_order_id', $po->id)->with('items')->get() as $grn) {
            foreach ($grn->items as $line) {
                $key = $line->item_id ? 'id:' . $line->item_id : 'code:' . $line->item_code;
                $totals[$key] = ($totals[$key] ?? 0) + (float) $line->received_quantity;
            }
        }

        foreach ($po->items()->get() as $poItem) {
            $key = $poItem->item_id ? 'id:' . $poItem->item_id : 'code:' . $poItem->item_code;
            $poItem->update(['received_quantity' => $totals[$key] ?? 0]);
        }
    }

    public function show($id)
    {
        $grn = GoodsReceipt::with(['items', 'purchaseOrder.items', 'purchaseOrder.purchaseRequest', 'purchaseOrder.quotation', 'attachments'])->find((int) $id);
        abort_unless($grn, 404);

        $data = $grn->toArray();
        $data['warehouse_name'] = $this->warehouseLabel($grn->warehouse_id);
        $data['received_by_name'] = $this->userName($grn->received_by_user_id);
        $data['attachments'] = $grn->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->title ?: $a->filename])->values();
        $data['trace'] = $grn->purchaseOrder ? $this->traceForPurchaseOrder($grn->purchaseOrder) : null;

        return Inertia::render('SupplierRecords/GoodsReceipt/Show', ['goodsReceipt' => $data]);
    }

    public function edit(Request $request, $id)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403, 'Confirmed goods receipts can only be edited by a Super Admin.');

        $grn = GoodsReceipt::with(['items', 'purchaseOrder.items'])->findOrFail($id);

        return Inertia::render('SupplierRecords/GoodsReceipt/Form', [
            'goodsReceipt' => $grn,
            'purchaseOrder' => $grn->purchaseOrder,
            'trace' => $grn->purchaseOrder ? $this->traceForPurchaseOrder($grn->purchaseOrder) : null,
            'items' => $this->itemOptions(),
            'purchaseOrders' => [],
        ]);
    }

    public function update(Request $request, $id)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403, 'Confirmed goods receipts can only be edited by a Super Admin.');

        $data = $request->validate([
            'date_received' => 'required|date',
            'remarks' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'required|string|max:190',
            'items.*.item_code' => 'nullable|string|max:60',
            'items.*.unit_label' => 'nullable|string|max:30',
            'items.*.received_quantity' => 'required|numeric|min:0',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
        ]);

        $grn = DB::transaction(function () use ($request, $id, $data) {
            $grn = GoodsReceipt::with('purchaseOrder')->lockForUpdate()->findOrFail($id);
            $po = $grn->purchaseOrder;

            if ($po) {
                PurchaseOrder::whereKey($po->id)->lockForUpdate()->first();
                $ledger = $this->purchaseOrderItemLedger($po, $grn->id);
                $items = $this->validateAndBuildReceiptLines($data['items'], $ledger);
            } else {
                // Orphaned GRN (its PO was removed) — nothing left to validate against.
                $items = array_map(fn ($l) => [
                    'item_id' => $l['item_id'] ?? null, 'item_name' => $l['item_name'],
                    'item_code' => $l['item_code'] ?? null, 'unit_label' => $l['unit_label'] ?? null,
                    'ordered_quantity' => 0, 'received_quantity' => (float) $l['received_quantity'],
                ], $data['items']);
            }

            $grn->update(['date_received' => $data['date_received'], 'remarks' => $data['remarks'] ?? null]);

            $grn->items()->delete();
            foreach ($items as $line) {
                $grn->items()->create($line);
            }

            if ($request->hasFile('attachments')) {
                $grn->saveAttachments($request->file('attachments'), 'goods-receipt');
            }

            if ($po) {
                $this->syncPurchaseOrderItemReceivedQuantities($po);

                $fullyReceived = $po->items()->get()->every(fn ($i) => (float) $i->received_quantity >= (float) $i->quantity - 0.0001);
                $anyReceived = $po->items()->get()->contains(fn ($i) => (float) $i->received_quantity > 0);
                $po->update([
                    'status' => $fullyReceived ? PurchaseOrder::STATUS_COMPLETED : ($anyReceived ? PurchaseOrder::STATUS_PARTIALLY_DELIVERED : $po->status),
                ]);

                Ledger::recordObligation($po, $this->receivedValue($po), $grn);
            }

            return $grn;
        });

        log_activity(__('Goods Receipt edited.'), ['reference' => $grn->reference, 'edited_by_user_id' => $request->user()->id], $grn, 'Goods Receipt');
        \App\Services\SupplierRecordsNotifier::record('goods_receipt_updated', $grn->fresh(), $request->user(), 'Goods Receipt', 'edited');

        return redirect()->route('supplier-records.goods-receipts.show', $grn->id)->with('message', __('Goods Receipt updated.'));
    }

    public function print(Request $request, $id)
    {
        $this->authorize('print-goods-receipts');

        return view('supplier-records.goods-receipt-print', $this->printData($this->printable($id), false));
    }

    public function pdf(Request $request, $id)
    {
        $this->authorize('print-goods-receipts');
        $grn = $this->printable($id);

        return Pdf::loadView('supplier-records.goods-receipt-print', $this->printData($grn, true))->download('goods-receipt-' . $grn->reference . '.pdf');
    }

    private function printable($id): GoodsReceipt
    {
        return GoodsReceipt::with(['items', 'purchaseOrder.items'])->findOrFail((int) $id);
    }

    private function printData(GoodsReceipt $grn, bool $isPdf): array
    {
        $po = $grn->purchaseOrder;
        // quantities received against the whole order (every receipt), from the same ledger the receiving screen validates against
        $ledger = $po ? $this->purchaseOrderItemLedger($po) : [];

        return [
            'receipt' => [
                'id' => $grn->id,
                'reference' => $grn->reference,
                'status' => $grn->status,
                'date_received' => optional($grn->date_received)->format('d M Y'),
                'recorded_at' => optional($grn->created_at)->format('d M Y H:i'),
                'remarks' => $grn->remarks,
                'warehouse_name' => $this->warehouseLabel($grn->warehouse_id),
                'received_by_name' => $this->userName($grn->received_by_user_id),
                'purchase_order_reference' => $po->reference ?? null,
                'purchase_order_status' => $po->status ?? null,
                'supplier_name' => $po ? $this->supplierName($po->supplier_id) : null,
                'items' => $grn->items->map(function ($line) use ($ledger) {
                    $key = $line->item_id ? 'id:' . $line->item_id : 'code:' . $line->item_code;
                    $entry = $ledger[$key] ?? null;

                    return [
                        'item_name' => $line->item_name,
                        'item_code' => $line->item_code,
                        'unit_label' => $line->unit_label,
                        'ordered' => (float) $line->ordered_quantity,
                        'received' => (float) $line->received_quantity,
                        'received_to_date' => $entry ? (float) $entry['received'] : null,
                        'outstanding' => $entry ? max(round($entry['ordered'] - $entry['received'], 4), 0) : null,
                    ];
                })->values()->all(),
            ],
            'companyName' => PurchaseOrder::COMPANY_NAME,
            'qr' => PrintQrCode::dataUri(route('supplier-records.goods-receipts.show', $grn->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('goods_receipt', $grn),
        ];
    }

    public function downloadAttachment(Request $request, $id, Attachment $attachment)
    {
        $this->authorize('read-goods-receipts');
        abort_unless((int) $attachment->model_id === (int) $id && $attachment->model_type === GoodsReceipt::class, 404);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    public function destroy(Request $request, $id)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403, 'Goods receipts can only be removed by a Super Admin.');

        DB::transaction(function () use ($id) {
            $grn = GoodsReceipt::with('purchaseOrder')->lockForUpdate()->findOrFail($id);
            $po = $grn->purchaseOrder;

            $grn->items()->delete();
            $grn->delete();

            if ($po) {
                $this->syncPurchaseOrderItemReceivedQuantities($po);

                $fullyReceived = $po->items()->get()->every(fn ($i) => (float) $i->received_quantity >= (float) $i->quantity - 0.0001);
                $anyReceived = $po->items()->get()->contains(fn ($i) => (float) $i->received_quantity > 0);
                $po->update([
                    'status' => $fullyReceived ? PurchaseOrder::STATUS_COMPLETED : ($anyReceived ? PurchaseOrder::STATUS_PARTIALLY_DELIVERED : PurchaseOrder::STATUS_SENT),
                ]);

                // Recomputed (not left stale) so a deleted GRN's obligation
                // amount is reversed out of the ledger rather than lingering
                // as a phantom debit with nothing behind it.
                Ledger::recordObligation($po, $this->receivedValue($po), null);
            }
        });

        $removed = GoodsReceipt::withTrashed()->find((int) $id);
        log_activity(__('Goods Receipt removed.'), ['reference' => optional($removed)->reference, 'removed_by_user_id' => $request->user()->id], $removed, 'Goods Receipt');
        \App\Services\SupplierRecordsNotifier::record('goods_receipt_deleted', $removed, $request->user(), 'Goods Receipt', 'deleted');

        return redirect()->route('supplier-records.goods-receipts.index')->with('message', __('Goods Receipt removed.'));
    }

    /**
     * GoodsReceiptItem rows carry quantities only, not price — value has to
     * come from the parent PO's own items. Summed across ALL of the PO's
     * confirmed receipts (not just the one just created) so a running
     * Material Obligation stays accurate through multiple partial
     * deliveries; Ledger::recordObligation() upserts on the PO, so calling
     * this again after each GRN simply corrects the one obligation entry.
     */
    private function receivedValue(PurchaseOrder $po): float
    {
        $prices = $po->items()->get()->reduce(function (array $map, $poItem) {
            if ($poItem->item_id) {
                $map['id:' . $poItem->item_id] = (float) $poItem->unit_price;
            }
            if ($poItem->item_code) {
                $map['code:' . $poItem->item_code] = (float) $poItem->unit_price;
            }

            return $map;
        }, []);
        $fallbackPrice = $po->items()->count() === 1 ? (float) $po->items()->value('unit_price') : null;

        $total = 0.0;
        foreach (GoodsReceipt::where('purchase_order_id', $po->id)->with('items')->get() as $grn) {
            foreach ($grn->items as $line) {
                $price = ($line->item_id && isset($prices['id:' . $line->item_id]))
                    ? $prices['id:' . $line->item_id]
                    : (($line->item_code && isset($prices['code:' . $line->item_code]))
                        ? $prices['code:' . $line->item_code]
                        : $fallbackPrice);

                $total += (float) $line->received_quantity * (float) ($price ?? 0);
            }
        }

        return round($total, 2);
    }

    private function warehouseLabel(?int $warehouseId): ?string
    {
        if (! $warehouseId) {
            return PurchaseOrder::COMPANY_NAME;
        }

        return Warehouse::find($warehouseId)?->name;
    }

    private function nextReference(): string
    {
        $maxSuffix = (int) DB::table('goods_receipts')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix')
            ->where('reference', 'like', 'GRN-%')
            ->value('max_suffix');

        return 'GRN-' . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }
}
