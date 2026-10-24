<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupplierRecords\Concerns\HasOwnModulePermission;
use App\Http\Controllers\SupplierRecords\Concerns\SharedLookups;
use App\Http\Resources\SupplierRecords\SupplierRecordsCollection;
use App\Models\PurchaseRequest;
use App\Services\Signatories\Documents\PurchaseRequestDocument;
use App\Services\Signatories\Documents\Support\RefusesWithoutSignatures;
use App\Services\Signatories\SignatureResolver;
use App\Services\SupplierRecordsNotifier;
use App\Support\SupplierRecords\RequestStamps;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class PurchaseRequestController extends Controller
{
    use SharedLookups, HasOwnModulePermission;

    public const STATUSES = ['Draft', 'Pending Approval', 'Approved', 'Rejected', 'Converted to PO'];

    protected function modulePermissionSlug(): string
    {
        return 'purchase-requests';
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));

        $requests = PurchaseRequest::query()
            ->with(['warehouse:id,name', 'user:id,name'])
            ->filter(['status' => $status, 'search' => $search !== '' ? $search : null])
            ->orderByDesc('date_requested')
            ->paginate()
            ->withQueryString()
            ->through(fn (PurchaseRequest $pr) => $this->presentSummary($pr));

        $counts = PurchaseRequest::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('SupplierRecords/PurchaseRequest/Index', [
            'filters' => ['status' => $status, 'search' => $search],
            'statuses' => self::STATUSES,
            'stats' => [
                'total' => (int) $counts->sum(),
                'draft' => (int) ($counts[PurchaseRequest::STATUS_DRAFT] ?? 0),
                'pending' => (int) ($counts[PurchaseRequest::STATUS_PENDING_APPROVAL] ?? 0),
                'approved' => (int) ($counts[PurchaseRequest::STATUS_APPROVED] ?? 0),
                'rejected' => (int) ($counts[PurchaseRequest::STATUS_REJECTED] ?? 0),
                'converted' => (int) ($counts[PurchaseRequest::STATUS_CONVERTED] ?? 0),
            ],
            'purchaseRequests' => new SupplierRecordsCollection($requests),
        ]);
    }

    public function create()
    {
        return Inertia::render('SupplierRecords/PurchaseRequest/Form', [
            'purchaseRequest' => null,
            'warehouses' => $this->warehouseOptions(),
            'items' => $this->itemOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $pr = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $pr = new PurchaseRequest();
                $pr->fill([
                    'warehouse_id' => $data['warehouse_id'],
                    'date_requested' => $data['date_requested'],
                    'reason' => $data['reason'],
                    'status' => PurchaseRequest::STATUS_PENDING_APPROVAL,
                    'user_id' => $request->user()->id,
                    'approval_remarks' => null,
                    'reference' => $this->nextReference(),
                ] + RequestStamps::submitted((int) $request->user()->id));
                $pr->save();
                break;
            } catch (QueryException $e) {
                $isDuplicateReference = (string) $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'purchase_requests_reference_unique');

                if (! $isDuplicateReference || $attempt === 2) {
                    throw $e;
                }
                $pr = null;
            }
        }

        if (! $pr) {
            abort(500, 'Unable to generate a unique purchase request reference.');
        }

        foreach ($data['items'] as $item) {
            $pr->items()->create($item);
        }

        if ($request->hasFile('attachments')) {
            $pr->saveAttachments($request->file('attachments'), 'purchase-request');
        }

        RequestStamps::log($pr, 'Purchase Request', 'submitted', null, PurchaseRequest::STATUS_PENDING_APPROVAL);
        SupplierRecordsNotifier::purchaseRequest('submitted', $pr, $request->user());

        return redirect()->route('supplier-records.purchase-requests.show', $pr->id)->with('message', __('Purchase Request submitted.'));
    }

    public function show($id)
    {
        $pr = PurchaseRequest::with(['items', 'warehouse:id,name', 'user:id,name', 'attachments'])->findOrFail($id);

        $quotations = $pr->quotations()->get(['id', 'reference', 'status'])->values();
        $purchaseOrders = $pr->purchaseOrders()->get(['id', 'reference', 'status'])->values();

        return Inertia::render('SupplierRecords/PurchaseRequest/Show', [
            'purchaseRequest' => $this->presentDetail($pr),
            'quotations' => $quotations,
            'purchaseOrders' => $purchaseOrders,
        ]);
    }

    public function downloadAttachment(Request $request, $id, Attachment $attachment)
    {
        $this->authorize('read-purchase-requests');
        abort_unless((int) $attachment->model_id === (int) $id && $attachment->model_type === PurchaseRequest::class, 404);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    public function edit($id)
    {
        $pr = PurchaseRequest::with(['items', 'warehouse:id,name', 'user:id,name', 'attachments'])->findOrFail($id);
        abort_unless(in_array($pr->status, [PurchaseRequest::STATUS_DRAFT, PurchaseRequest::STATUS_PENDING_APPROVAL], true), 422, 'This request can no longer be edited.');

        return Inertia::render('SupplierRecords/PurchaseRequest/Form', [
            'purchaseRequest' => $this->presentDetail($pr),
            'warehouses' => $this->warehouseOptions(),
            'items' => $this->itemOptions(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $pr = PurchaseRequest::findOrFail($id);
        // Same window edit() already enforces — without repeating it here, a
        // direct PATCH bypasses that check entirely and can silently change
        // an Approved (or already converted-to-PO) request's items/warehouse.
        abort_unless(in_array($pr->status, [PurchaseRequest::STATUS_DRAFT, PurchaseRequest::STATUS_PENDING_APPROVAL], true), 422, 'This request can no longer be edited.');
        $data = $this->validated($request);

        $pr->update([
            'warehouse_id' => $data['warehouse_id'],
            'date_requested' => $data['date_requested'],
            'reason' => $data['reason'],
        ]);

        $pr->items()->delete();
        foreach ($data['items'] as $item) {
            $pr->items()->create($item);
        }

        if ($request->hasFile('attachments')) {
            $pr->saveAttachments($request->file('attachments'), 'purchase-request');
        }

        \App\Services\SupplierRecordsNotifier::record('purchase_request_updated', $pr->fresh(), $request->user(), 'Purchase Request', 'edited');

        return redirect()->route('supplier-records.purchase-requests.show', $id)->with('message', __('Purchase Request updated.'));
    }

    public function destroy($id)
    {
        $pr = PurchaseRequest::findOrFail($id);
        abort_unless(in_array($pr->status, [PurchaseRequest::STATUS_DRAFT, PurchaseRequest::STATUS_PENDING_APPROVAL], true), 422, 'This request can no longer be deleted.');
        abort_if(
            \App\Models\PurchaseOrder::where('purchase_request_id', $pr->id)->exists(),
            422,
            'A Purchase Order has already been created from this request and it can no longer be deleted.'
        );

        $pr->delete();
        \App\Services\SupplierRecordsNotifier::record('purchase_request_deleted', $pr, auth()->user(), 'Purchase Request', 'deleted');

        return redirect()->route('supplier-records.purchase-requests.index')->with('message', __('Purchase Request removed.'));
    }

    public function updateStatus(Request $request, $id)
    {
        $this->authorize('approve-purchase-requests');

        $data = $request->validate([
            'status' => 'required|in:Approved,Rejected',
            'approval_remarks' => 'nullable|string|max:1000',
        ]);

        $purchaseRequest = PurchaseRequest::findOrFail($id);
        abort_unless($purchaseRequest->status === PurchaseRequest::STATUS_PENDING_APPROVAL, 422, 'This purchase request is not awaiting approval.');

        $approving = $data['status'] === PurchaseRequest::STATUS_APPROVED;
        $userId = (int) $request->user()->id;
        // Signatories: when the document setup requires a signature and blocks finalising, refuse the approval without it (nothing changes with the default setup).
        if ($approving && ($blocked = RefusesWithoutSignatures::message('purchase_request', app(PurchaseRequestDocument::class)->asIfApproved($purchaseRequest, $userId), __('approved')))) {
            return back()->with('error', $blocked);
        }

        $from = $purchaseRequest->status;
        DB::transaction(function () use ($purchaseRequest, $data, $approving, $userId, $from) {
            $purchaseRequest->update($data + ($approving ? RequestStamps::approved($userId) : RequestStamps::rejected($userId)));
            RequestStamps::log($purchaseRequest, 'Purchase Request', $approving ? 'approved' : 'rejected', $from, $data['status'], $data['approval_remarks'] ?? null);
        });
        SupplierRecordsNotifier::purchaseRequest($data['status'] === 'Approved' ? 'approved' : 'rejected', $purchaseRequest->fresh(), $request->user());

        return back()->with('message', __('Purchase Request :status.', ['status' => strtolower($data['status'])]));
    }

    public function print($id)
    {
        $this->authorize('print-purchase-requests');
        $pr = $this->printable($id);

        return view('supplier-records.purchase-request-print', $this->printData($pr, false));
    }

    public function pdf($id)
    {
        $this->authorize('print-purchase-requests');
        $pr = $this->printable($id);

        return Pdf::loadView('supplier-records.purchase-request-print', $this->printData($pr, true))->download('purchase-request-' . $pr->reference . '.pdf');
    }

    private function printable($id): PurchaseRequest
    {
        return PurchaseRequest::with(['items', 'warehouse:id,name', 'user:id,name'])->findOrFail($id);
    }

    private function printData(PurchaseRequest $pr, bool $isPdf): array
    {
        $name = fn ($uid) => $uid ? \App\Models\User::query()->whereKey($uid)->value('name') : null;
        $standing = in_array($pr->status, [PurchaseRequest::STATUS_APPROVED, PurchaseRequest::STATUS_CONVERTED], true);

        return [
            'pr' => [
                'reference' => $pr->reference,
                'status' => $pr->status,
                'is_draft' => $pr->status === PurchaseRequest::STATUS_DRAFT,
                'date_requested' => optional($pr->date_requested)->format('d M Y'),
                'warehouse_name' => $pr->warehouse?->name,
                'requested_by' => $pr->user?->name,
                'requested_at' => optional($pr->created_at)->format('d M Y H:i'),
                'reason' => $pr->reason,
                'remarks' => $pr->approval_remarks,
                // only the decision that still stands, and only when it was stamped (older requests carry none)
                'decision' => $standing && $pr->approved_at ? ['label' => 'Approved', 'by' => $name($pr->approved_by_user_id), 'at' => $pr->approved_at->format('d M Y H:i')]
                    : ($pr->status === PurchaseRequest::STATUS_REJECTED && $pr->rejected_at ? ['label' => 'Rejected', 'by' => $name($pr->rejected_by_user_id), 'at' => $pr->rejected_at->format('d M Y H:i')] : null),
                'items' => $pr->items->map(fn ($i) => [
                    'code' => $i->item_code, 'name' => $i->item_name, 'unit' => $i->unit_label, 'quantity' => (float) $i->quantity, 'remarks' => $i->remarks,
                ])->values()->all(),
                'total_quantity' => (float) $pr->items->sum('quantity'),
                'quotations' => $pr->quotations()->pluck('reference')->all(),
                'purchase_orders' => $pr->purchaseOrders()->pluck('reference')->all(),
            ],
            'companyName' => \App\Models\PurchaseOrder::COMPANY_NAME,
            'qr' => \App\Support\PrintQrCode::dataUri(route('supplier-records.purchase-requests.show', $pr->id)),
            'isPdf' => $isPdf,
            'pdfUrl' => route('supplier-records.purchase-requests.pdf', $pr->id),
            'signatures' => app(SignatureResolver::class)->resolve('purchase_request', $pr),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => 'required|integer',
            'date_requested' => 'required|date',
            'reason' => 'required|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'required|string|max:190',
            'items.*.item_code' => 'nullable|string|max:60',
            'items.*.unit_label' => 'nullable|string|max:30',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.remarks' => 'nullable|string|max:300',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
        ]);
    }

    private function nextReference(): string
    {
        $maxSuffix = (int) \Illuminate\Support\Facades\DB::table('purchase_requests')
            ->selectRaw('MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)) as max_suffix')
            ->where('reference', 'like', 'PR-%')
            ->value('max_suffix');

        return 'PR-' . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    private function presentSummary(PurchaseRequest $pr): array
    {
        return [
            'id' => $pr->id,
            'reference' => $pr->reference,
            'warehouse_id' => $pr->warehouse_id,
            'warehouse_name' => $pr->warehouse?->name,
            'user_id' => $pr->user_id,
            'user_name' => $pr->user?->name,
            'date_requested' => optional($pr->date_requested)->format('Y-m-d'),
            'status' => $pr->status,
        ];
    }

    private function presentDetail(PurchaseRequest $pr): array
    {
        return array_merge($this->presentSummary($pr), [
            'reason' => $pr->reason,
            'approval_remarks' => $pr->approval_remarks,
            'items' => $pr->items->map(fn ($item) => [
                'id' => $item->id,
                'item_id' => $item->item_id,
                'item_name' => $item->item_name,
                'item_code' => $item->item_code,
                'unit_label' => $item->unit_label,
                'quantity' => (float) $item->quantity,
                'remarks' => $item->remarks,
            ])->values(),
            'attachments' => $pr->attachments->map(fn (\Tecdiary\Laravel\Attachments\Attachment $a) => [
                'id' => $a->id,
                'name' => $a->title ?: $a->filename,
            ])->values(),
        ]);
    }
}
