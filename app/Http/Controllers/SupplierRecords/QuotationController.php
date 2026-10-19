<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Http\Resources\SupplierRecords\SupplierRecordsCollection;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Services\Signatories\Documents\QuotationDocument;
use App\Services\Signatories\Documents\Support\RefusesWithoutSignatures;
use App\Services\Signatories\SignatureResolver;
use App\Services\SupplierRecordsNotifier;
use App\Support\SupplierRecords\RequestStamps;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class QuotationController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    public const STATUSES = ['Draft', 'Sent', 'Received', 'Selected', 'Rejected'];

    protected function modulePermissionSlug(): string
    {
        return 'quotations';
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        $supplierId = $request->query('supplier_id');

        $quotations = Quotation::query()
            ->with('supplier:id,name')
            ->filter(['status' => $status, 'supplier_id' => $supplierId])
            ->orderByDesc('date')
            ->paginate()
            ->withQueryString()
            ->through(fn (Quotation $q) => $this->presentSummary($q));

        $counts = Quotation::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('SupplierRecords/Quotation/Index', [
            'filters' => ['status' => $status, 'supplier_id' => $supplierId],
            'statuses' => self::STATUSES,
            'stats' => [
                'total' => (int) $counts->sum(),
                'draft' => (int) ($counts[Quotation::STATUS_DRAFT] ?? 0),
                'sent' => (int) ($counts[Quotation::STATUS_SENT] ?? 0),
                'received' => (int) ($counts[Quotation::STATUS_RECEIVED] ?? 0),
                'selected' => (int) ($counts[Quotation::STATUS_SELECTED] ?? 0),
                'rejected' => (int) ($counts[Quotation::STATUS_REJECTED] ?? 0),
            ],
            'quotations' => new SupplierRecordsCollection($quotations),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('SupplierRecords/Quotation/Form', [
            'quotation' => null,
            'suppliers' => $this->supplierOptions(),
            'warehouses' => $this->warehouseOptions(),
            'items' => $this->itemOptions(),
            'purchaseRequests' => $this->openPurchaseRequestOptions(),
            'prefillPurchaseRequestId' => $request->query('purchase_request_id'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $q = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $q = new Quotation();
                $q->fill([
                    'purchase_request_id' => $data['purchase_request_id'] ?? null,
                    'supplier_id' => $data['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'] ?? null,
                    'date' => $data['date'],
                    'valid_until' => $data['valid_until'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'total' => $data['total'],
                    'status' => Quotation::STATUS_DRAFT,
                    'reference' => $this->nextReference(),
                    'created_by' => $request->user()->id,
                ]);
                $q->save();
                break;
            } catch (QueryException $e) {
                $isDuplicateReference = (string) $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'quotations_reference_unique');

                if (! $isDuplicateReference || $attempt === 2) {
                    throw $e;
                }
                $q = null;
            }
        }

        if (! $q) {
            abort(500, 'Unable to generate a unique quotation reference.');
        }

        foreach ($data['items'] as $item) {
            $q->items()->create($item);
        }

        \App\Services\SupplierRecordsNotifier::record('quotation_created', $q, $request->user(), 'Quotation', 'recorded');

        return redirect()->route('supplier-records.quotations.show', $q->id)->with('message', __('Quotation recorded.'));
    }

    public function show($id)
    {
        $quotation = Quotation::with(['items', 'supplier:id,name', 'warehouse:id,name'])->findOrFail($id);

        return Inertia::render('SupplierRecords/Quotation/Show', ['quotation' => $this->presentDetail($quotation)]);
    }

    public function edit($id)
    {
        $quotation = Quotation::with(['items', 'supplier:id,name', 'warehouse:id,name'])->findOrFail($id);

        return Inertia::render('SupplierRecords/Quotation/Form', [
            'quotation' => $this->presentDetail($quotation),
            'suppliers' => $this->supplierOptions(),
            'warehouses' => $this->warehouseOptions(),
            'items' => $this->itemOptions(),
            'purchaseRequests' => $this->openPurchaseRequestOptions(),
            'prefillPurchaseRequestId' => null,
        ]);
    }

    public function update(Request $request, $id)
    {
        $quotation = Quotation::findOrFail($id);
        abort_if($quotation->status === Quotation::STATUS_SELECTED, 422, 'A selected quotation cannot be edited.');
        abort_if(PurchaseOrder::where('quotation_id', $quotation->id)->exists(), 422, 'This quotation is referenced by a purchase order and cannot be edited.');

        $data = $this->validated($request);

        $quotation->update([
            'purchase_request_id' => $data['purchase_request_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'date' => $data['date'],
            'valid_until' => $data['valid_until'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'total' => $data['total'],
        ]);

        $quotation->items()->delete();
        foreach ($data['items'] as $item) {
            $quotation->items()->create($item);
        }

        \App\Services\SupplierRecordsNotifier::record('quotation_updated', $quotation->fresh(), $request->user(), 'Quotation', 'edited');

        return redirect()->route('supplier-records.quotations.show', $id)->with('message', __('Quotation updated.'));
    }

    public function destroy($id)
    {
        $quotation = Quotation::findOrFail($id);
        abort_if($quotation->status === Quotation::STATUS_SELECTED, 422, 'A selected quotation cannot be deleted.');
        abort_if(PurchaseOrder::where('quotation_id', $quotation->id)->exists(), 422, 'This quotation is referenced by a purchase order and cannot be deleted.');

        $quotation->delete();
        \App\Services\SupplierRecordsNotifier::record('quotation_deleted', $quotation, auth()->user(), 'Quotation', 'deleted');

        return redirect()->route('supplier-records.quotations.index')->with('message', __('Quotation removed.'));
    }

    public function updateStatus(Request $request, $id)
    {
        $this->authorize('update-quotations');

        $data = $request->validate(['status' => 'required|in:Sent,Received,Selected,Rejected']);
        $quotation = Quotation::findOrFail($id);
        $before = $quotation->status;
        $userId = (int) $request->user()->id;
        if ($before !== $data['status']) {
            // Signatories: when the document setup requires a signature and blocks finalising, refuse selecting the quotation without it (nothing changes with the default setup).
            if ($data['status'] === Quotation::STATUS_SELECTED && ($blocked = RefusesWithoutSignatures::message('quotation', app(QuotationDocument::class)->asIfSelected($quotation, $userId), __('selected')))) {
                return back()->with('error', $blocked);
            }
            DB::transaction(function () use ($quotation, $data, $before, $userId) {
                $quotation->update($data + RequestStamps::quotation($data['status'], $userId));
                RequestStamps::log($quotation, 'Quotation', mb_strtolower($data['status']), $before, $data['status']);
            });
        }
        if ($before !== $data['status'] && ($event = ['Received' => 'received', 'Selected' => 'selected', 'Rejected' => 'rejected'][$data['status']] ?? null)) {
            SupplierRecordsNotifier::quotation($event, $quotation->fresh(), $request->user());
        }

        return back()->with('message', __('Quotation status updated.'));
    }

    public function print($id)
    {
        $this->authorize('print-quotations');
        $quotation = $this->printable($id);

        return view('supplier-records.quotation-print', $this->printData($quotation, false));
    }

    public function pdf($id)
    {
        $this->authorize('print-quotations');
        $quotation = $this->printable($id);

        return Pdf::loadView('supplier-records.quotation-print', $this->printData($quotation, true))->download('quotation-' . $quotation->reference . '.pdf');
    }

    private function printable($id): Quotation
    {
        return Quotation::with(['items', 'supplier:id,name', 'warehouse:id,name', 'purchaseRequest:id,reference'])->findOrFail($id);
    }

    private function printData(Quotation $q, bool $isPdf): array
    {
        $lines = $q->items->map(fn ($i) => [
            'code' => $i->item_code, 'name' => $i->item_name, 'unit' => $i->unit_label, 'quantity' => (float) $i->quantity, 'unit_price' => (float) $i->unit_price,
            'line_total' => round((float) $i->quantity * (float) $i->unit_price, 2),
        ])->values()->all();
        $standing = fn (string $step, array $statuses) => in_array($q->status, $statuses, true) && $q->{$step . '_at'}
            ? ['by' => $this->userName($q->{$step . '_by_user_id'}), 'at' => $q->{$step . '_at'}->format('d M Y H:i')] : null;

        return [
            'q' => [
                'reference' => $q->reference,
                'status' => $q->status,
                'is_draft' => $q->status === Quotation::STATUS_DRAFT,
                'supplier_name' => $q->supplier?->name,
                'warehouse_name' => $q->warehouse?->name,
                'purchase_request' => $q->purchaseRequest?->reference,
                'date' => optional($q->date)->format('d M Y'),
                'valid_until' => optional($q->valid_until)->format('d M Y'),
                'recorded_by' => $this->userName($q->created_by),
                'recorded_at' => optional($q->created_at)->format('d M Y H:i'),
                'remarks' => $q->remarks,
                'selected' => $standing('selected', [Quotation::STATUS_SELECTED]),
                'rejected' => $standing('rejected', [Quotation::STATUS_REJECTED]),
                'items' => $lines,
                'total' => (float) $q->total,
            ],
            'companyName' => \App\Models\PurchaseOrder::COMPANY_NAME,
            'qr' => \App\Support\PrintQrCode::dataUri(route('supplier-records.quotations.show', $q->id)),
            'isPdf' => $isPdf,
            'pdfUrl' => route('supplier-records.quotations.pdf', $q->id),
            'signatures' => app(SignatureResolver::class)->resolve('quotation', $q),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'purchase_request_id' => 'nullable|integer',
            'supplier_id' => 'required|integer',
            'warehouse_id' => 'nullable|integer',
            'date' => 'required|date',
            'valid_until' => 'nullable|date',
            'remarks' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'required|string|max:190',
            'items.*.item_code' => 'nullable|string|max:60',
            'items.*.unit_label' => 'nullable|string|max:30',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $data['total'] = collect($data['items'])->sum(fn ($l) => $l['quantity'] * $l['unit_price']);

        return $data;
    }

    private function nextReference(): string
    {
        $maxSuffix = (int) \Illuminate\Support\Facades\DB::table('quotations')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)) as max_suffix')
            ->where('reference', 'like', 'QT-%')
            ->value('max_suffix');

        return 'QT-' . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    private function openPurchaseRequestOptions()
    {
        return PurchaseRequest::whereIn('status', [PurchaseRequest::STATUS_PENDING_APPROVAL, PurchaseRequest::STATUS_APPROVED])
            ->get(['id', 'reference'])
            ->values();
    }

    private function presentSummary(Quotation $q): array
    {
        return [
            'id' => $q->id,
            'reference' => $q->reference,
            'purchase_request_id' => $q->purchase_request_id,
            'supplier_id' => $q->supplier_id,
            'supplier_name' => $q->supplier?->name,
            'date' => optional($q->date)->format('Y-m-d'),
            'valid_until' => optional($q->valid_until)->format('Y-m-d'),
            'status' => $q->status,
            'total' => (float) $q->total,
            'created_by' => $q->created_by,
            'created_by_name' => $this->userName($q->created_by),
        ];
    }

    private function presentDetail(Quotation $q): array
    {
        return array_merge($this->presentSummary($q), [
            'warehouse_id' => $q->warehouse_id,
            'warehouse_name' => $q->warehouse?->name,
            'remarks' => $q->remarks,
            'items' => $q->items->map(fn ($item) => [
                'id' => $item->id,
                'item_id' => $item->item_id,
                'item_name' => $item->item_name,
                'item_code' => $item->item_code,
                'unit_label' => $item->unit_label,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
            ])->values(),
        ]);
    }
}
