<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueMaterialRequest;
use App\Http\Resources\IssueMaterialCollection;
use App\Models\IssueReturnLog;
use App\Models\IssueTransaction;
use App\Models\IssueTransactionItem;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\IssueReturnLogService;
use App\Services\IssueReturnNotificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;
use App\Traits\GuardsCompletedWarehouse;

class IssueMaterialController extends Controller
{
    use GuardsCompletedWarehouse;

    public function __construct()
    {
        // This module uses explicit role/permission guards per action.
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($this->canRead(), 403);

        $filters = $request->all(
            'search',
            'issue_number',
            'start_date',
            'end_date',
            'warehouse_id',
            'project',
            'issued_to',
            'contact_number',
            'item_id',
            'status',
            'created_by'
        );

        return Inertia::render('IssueReturn/IssueIndex', [
            'filters' => $filters,
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at']),
            'items' => Item::ofAccount()->orderBy('name')->get(['id', 'name', 'code']),
            'users' => User::ofAccount()->orderBy('name')->get(['id', 'name']),
            'issues' => new IssueMaterialCollection(
                IssueTransaction::query()
                    ->with(['warehouse:id,name', 'user:id,name', 'items:id,issue_transaction_id,item_id,issued_quantity', 'items.item:id,name,code'])
                    ->filter($filters)
                    ->when(warehouse_scope_ids() !== null, fn ($q) => $q->withoutGlobalScope('mine')->whereIn('warehouse_id', warehouse_scope_ids()))
                    ->when($this->isRestrictedToOwnTransactions(), fn ($q) => $q->where('user_id', auth()->id()))
                    ->orderByDesc('id')
                    ->paginate()
                    ->withQueryString()
            ),
        ]);
    }

    public function create()
    {
        abort_unless($this->canCreate(), 403);

        return Inertia::render('IssueReturn/IssueForm', [
            'warehouses' => $this->availableWarehouses(),
            'contacts' => Contact::ofAccount()->orderBy('name')->get(['id', 'name']),
            'items' => Item::ofAccount()->with(['unit:id,code,name', 'stock' => fn ($q) => $q->without(['variation', 'warehouse'])->where('quantity', '!=', 0)->select('id', 'item_id', 'warehouse_id', 'quantity')])
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'unit_id']),
        ]);
    }

    public function store(IssueMaterialRequest $request)
    {
        abort_unless($this->canCreate(), 403);

        $data = $request->validated();
        abort_if(! $this->canUseWarehouse((int) $data['warehouse_id']), 403);
        if ($response = $this->rejectIfWarehouseBlocked($data['warehouse_id'] ?? null)) {
            return $response;
        }

        $issue = DB::transaction(function () use ($data, $request) {
            $issue = IssueTransaction::query()->create([
                'issue_number' => $this->nextIssueNumber(),
                'issue_date' => $data['issue_date'],
                'warehouse_id' => $data['warehouse_id'],
                'project' => $data['project'],
                'issued_to' => $data['issued_to'],
                'user_id' => auth()->id(),
                'contact_number' => $data['contact_number'] ?? null,
                'department' => $data['department'] ?? null,
                'expected_return_date' => $data['expected_return_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->syncItemsAndStock($issue, $data['items'], true);

            if ($request->file('attachments')) {
                $issue->saveAttachments($request->file('attachments'));
                IssueReturnLogService::write('Upload Attachment', $issue, null, 'Issue attachments uploaded.');
            }

            $issue->refreshTotalsAndStatus();

            IssueReturnLogService::write('Create Issue', $issue, null, 'Issue transaction created.');
            IssueReturnNotificationService::notifyNewIssue($issue);

            return $issue;
        });

        return redirect()->route('issue-materials.show', $issue->id)->with('message', 'Issue created successfully.');
    }

    public function show(IssueTransaction $issueMaterial)
    {
        abort_unless($this->canRead(), 403);
        abort_if($this->isRestrictedToOwnTransactions() && (int) $issueMaterial->user_id !== (int) auth()->id(), 403);

        $issueMaterial->refreshTotalsAndStatus();
        $issueMaterial = $issueMaterial->fresh();

        $issueMaterial->load([
            'warehouse:id,name,code',
            'user:id,name',
            'items.item:id,name,code',
            'items.unit:id,code,name',
            'returns.user:id,name',
            'returns.items.item:id,name,code',
            'returns.items.unit:id,code,name',
            'returns.attachments',
            'attachments',
        ]);

        // Preview/download/delete URLs built server-side (same shape as
        // AssetController::presentDetail()) so the Show page can offer
        // View/Download/Remove per file without constructing routes itself.
        $issueMaterial->setRelation('attachments', $issueMaterial->attachments->map(fn (Attachment $a) => [
            'id' => $a->id,
            'title' => $a->title,
            'filesize' => $a->filesize,
            'is_image' => in_array(strtolower($a->filetype ?? ''), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true),
            'preview_url' => route('issue-materials.attachments.download', [$issueMaterial->id, $a->id]) . '?disposition=inline',
            'download_url' => route('issue-materials.attachments.download', [$issueMaterial->id, $a->id]),
            'delete_url' => route('issue-materials.attachments.destroy', [$issueMaterial->id, $a->id]),
        ])->values());

        return Inertia::render('IssueReturn/IssueShow', [
            'issue' => $issueMaterial,
            'logs' => IssueReturnLog::query()
                ->with(['user:id,name', 'warehouse:id,name'])
                ->where('issue_transaction_id', $issueMaterial->id)
                ->latest('id')
                ->limit(200)
                ->get(),
        ]);
    }

    public function edit(IssueTransaction $issueMaterial)
    {
        abort_unless($this->canUpdate($issueMaterial), 403);
        abort_if($this->isRestrictedToOwnTransactions() && (int) $issueMaterial->user_id !== (int) auth()->id(), 403);
        if ($response = $this->rejectIfWarehouseBlocked($issueMaterial->warehouse_id)) {
            return $response;
        }

        if ($issueMaterial->returns()->exists()) {
            return redirect()->route('issue-materials.show', $issueMaterial->id)
                ->with('error', 'Issue can no longer be edited because returns are already recorded.');
        }

        $issueMaterial->load(['items.item:id,name,code,unit_id', 'items.unit:id,code,name']);

        return Inertia::render('IssueReturn/IssueForm', [
            'edit' => $issueMaterial,
            'warehouses' => $this->availableWarehouses(),
            'contacts' => Contact::ofAccount()->orderBy('name')->get(['id', 'name']),
            'items' => Item::ofAccount()->with(['unit:id,code,name', 'stock' => fn ($q) => $q->without(['variation', 'warehouse'])->where('quantity', '!=', 0)->select('id', 'item_id', 'warehouse_id', 'quantity')])
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'unit_id']),
        ]);
    }

    public function update(IssueMaterialRequest $request, IssueTransaction $issueMaterial)
    {
        abort_unless($this->canUpdate($issueMaterial), 403);
        abort_if($this->isRestrictedToOwnTransactions() && (int) $issueMaterial->user_id !== (int) auth()->id(), 403);

        if ($issueMaterial->returns()->exists()) {
            return redirect()->route('issue-materials.show', $issueMaterial->id)
                ->with('error', 'Issue can no longer be edited because returns are already recorded.');
        }

        $data = $request->validated();
        abort_if(! $this->canUseWarehouse((int) $data['warehouse_id']), 403);
        if ($response = $this->rejectIfWarehouseBlocked($issueMaterial->warehouse_id, $data['warehouse_id'] ?? null)) {
            return $response;
        }

        DB::transaction(function () use ($issueMaterial, $data, $request) {
            $issueMaterial->load('items.item');

            // Move all previously issued quantities back to stock before re-applying the edited lines.
            foreach ($issueMaterial->items as $oldItem) {
                $this->increaseStock($issueMaterial->warehouse_id, $oldItem->item_id, (float) $oldItem->issued_quantity, $oldItem->unit_id, 'Issue Edit Revert');
            }

            $issueMaterial->items()->forceDelete();

            $issueMaterial->update([
                'issue_date' => $data['issue_date'],
                'warehouse_id' => $data['warehouse_id'],
                'project' => $data['project'],
                'issued_to' => $data['issued_to'],
                'contact_number' => $data['contact_number'] ?? null,
                'department' => $data['department'] ?? null,
                'expected_return_date' => $data['expected_return_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->syncItemsAndStock($issueMaterial, $data['items'], true);

            if ($request->file('attachments')) {
                $issueMaterial->saveAttachments($request->file('attachments'));
                IssueReturnLogService::write('Upload Attachment', $issueMaterial, null, 'Issue attachments uploaded on update.');
            }

            $issueMaterial->refreshTotalsAndStatus();

            IssueReturnLogService::write('Edit Issue', $issueMaterial, null, 'Issue transaction updated.');
            IssueReturnNotificationService::notifyUpdated($issueMaterial);
        });

        return redirect()->route('issue-materials.show', $issueMaterial->id)->with('message', 'Issue updated successfully.');
    }

    public function destroy(IssueTransaction $issueMaterial)
    {
        abort_unless($this->canDelete(), 403, 'Only Admin or Procurement Manager can delete issue transactions.');
        abort_if($this->isRestrictedToOwnTransactions() && (int) $issueMaterial->user_id !== (int) auth()->id(), 403);
        if ($response = $this->rejectIfWarehouseBlocked($issueMaterial->warehouse_id)) {
            return $response;
        }

        if ($issueMaterial->returns()->exists()) {
            return back()->with('error', 'Issue cannot be deleted after returns are recorded.');
        }

        DB::transaction(function () use ($issueMaterial) {
            $issueMaterial->load('items');

            foreach ($issueMaterial->items as $item) {
                $this->increaseStock($issueMaterial->warehouse_id, $item->item_id, (float) $item->issued_quantity, $item->unit_id, 'Delete Issue');
            }

            $issueMaterial->items()->delete();
            $issueMaterial->delete();

            IssueReturnLogService::write('Delete Issue', $issueMaterial, null, 'Issue transaction deleted.');
            IssueReturnNotificationService::notifyDeleted($issueMaterial);
        });

        return redirect()->route('issue-materials.index')->with('message', 'Issue deleted successfully.');
    }

    public function print(IssueTransaction $issueMaterial)
    {
        abort_unless($this->canPrint(), 403);
        abort_if($this->isRestrictedToOwnTransactions() && (int) $issueMaterial->user_id !== (int) auth()->id(), 403);

        $issueMaterial->load([
            'warehouse:id,name,code',
            'user:id,name',
            'items.item:id,name,code',
            'items.unit:id,code,name',
            'returns.items.item:id,name,code',
        ]);

        IssueReturnLogService::write('Print', $issueMaterial, null, 'Issue printed.');

        return view('issue-return.print', [
            'issue' => $issueMaterial,
            'autoPrint' => true,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('issue_slip', $issueMaterial),
        ]);
    }

    public function pdf(IssueTransaction $issueMaterial)
    {
        abort_unless($this->canPrint(), 403);
        abort_if($this->isRestrictedToOwnTransactions() && (int) $issueMaterial->user_id !== (int) auth()->id(), 403);

        $issueMaterial->load([
            'warehouse:id,name,code',
            'user:id,name',
            'items.item:id,name,code',
            'items.unit:id,code,name',
            'returns.items.item:id,name,code',
        ]);

        IssueReturnLogService::write('Print', $issueMaterial, null, 'Issue PDF downloaded.');

        return Pdf::loadView('issue-return.print', [
            'issue' => $issueMaterial,
            'autoPrint' => false,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('issue_slip', $issueMaterial),
        ])->download('issue-' . $issueMaterial->issue_number . '.pdf');
    }

    public function downloadAttachment(Request $request, IssueTransaction $issueMaterial, Attachment $attachment)
    {
        abort_unless($this->canRead(), 403);
        abort_if($this->isRestrictedToOwnTransactions() && (int) $issueMaterial->user_id !== (int) auth()->id(), 403);

        if ((int) $attachment->model_id !== (int) $issueMaterial->id || $attachment->model_type !== IssueTransaction::class) {
            abort(404);
        }

        IssueReturnLogService::write('Download Attachment', $issueMaterial, null, 'Issue attachment downloaded: ' . $attachment->title);

        // ?disposition=inline lets the browser render the file directly
        // (used by the Show page's preview/zoom UI) instead of forcing a
        // download dialog — same convention as AssetController.
        if ($request->query('disposition') === 'inline') {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $attachment->title);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title);
    }

    public function destroyAttachment(IssueTransaction $issueMaterial, Attachment $attachment)
    {
        abort_unless($this->canUpdate($issueMaterial), 403);
        abort_if($this->isRestrictedToOwnTransactions() && (int) $issueMaterial->user_id !== (int) auth()->id(), 403);

        abort_unless((int) $attachment->model_id === (int) $issueMaterial->id && $attachment->model_type === IssueTransaction::class, 404);

        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        IssueReturnLogService::write('Delete Attachment', $issueMaterial, null, 'Issue attachment removed: ' . $attachment->title);

        return back()->with('message', 'Attachment removed.');
    }

    private function syncItemsAndStock(IssueTransaction $issue, array $items, bool $decrease): void
    {
        foreach (array_values($items) as $index => $line) {
            $item = Item::query()->findOrFail($line['item_id']);
            $unit = !empty($line['unit_id']) ? Unit::query()->find($line['unit_id']) : null;
            $qty = (float) $line['quantity'];
            $baseQty = $unit ? (float) convert_to_base_quantity($qty, $unit) : $qty;

            if ($baseQty <= 0) {
                continue;
            }

            if ($decrease) {
                $this->decreaseStock($issue->warehouse_id, $item->id, $baseQty, $line['unit_id'] ?? null, 'Issue Material');
            }

            IssueTransactionItem::query()->create([
                'issue_transaction_id' => $issue->id,
                'item_id' => $item->id,
                'unit_id' => $line['unit_id'] ?? null,
                'issued_quantity' => $baseQty,
                'returned_quantity' => 0,
                'damaged_quantity' => 0,
                'lost_quantity' => 0,
                'balance_quantity' => $baseQty,
                'remarks' => $line['remarks'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    private function decreaseStock(int $warehouseId, int $itemId, float $quantity, ?int $unitId, string $type): void
    {
        $stock = Stock::query()
            ->whereNull('variation_id')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();

        $available = (float) ($stock->quantity ?? 0);
        if (! $stock || $available < $quantity) {
            throw ValidationException::withMessages([
                'items' => 'Cannot issue more than available stock.',
            ]);
        }

        $newQty = $available - $quantity;
        $stock->update(['quantity' => $newQty]);

        $item = Item::query()->find($itemId);
        if ($item) {
            $item->stockTrails()->create([
                'variation_id' => null,
                'weight' => 0,
                'quantity' => $newQty,
                'item_id' => $itemId,
                'unit_id' => $unitId,
                'warehouse_id' => $warehouseId,
                'type' => $type,
            ]);
        }
    }

    private function increaseStock(int $warehouseId, int $itemId, float $quantity, ?int $unitId, string $type): void
    {
        $stock = Stock::query()
            ->whereNull('variation_id')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            $item = Item::query()->findOrFail($itemId);
            $stock = $item->stock()->create([
                'variation_id' => null,
                'weight' => 0,
                'quantity' => 0,
                'account_id' => getAccountId(),
                'warehouse_id' => $warehouseId,
                'rack_location' => $item->rack_location,
                'alert_quantity' => $item->alert_quantity,
            ]);
        }

        $newQty = (float) $stock->quantity + $quantity;
        $stock->update(['quantity' => $newQty]);

        $item = Item::query()->find($itemId);
        if ($item) {
            $item->stockTrails()->create([
                'variation_id' => null,
                'weight' => 0,
                'quantity' => $newQty,
                'item_id' => $itemId,
                'unit_id' => $unitId,
                'warehouse_id' => $warehouseId,
                'type' => $type,
            ]);
        }
    }

    private function nextIssueNumber(): string
    {
        $maxSuffix = (int) DB::table('issue_transactions')
            ->selectRaw("MAX(CAST(SUBSTRING(issue_number, 5) AS UNSIGNED)) as max_suffix")
            ->where('issue_number', 'like', 'IRM-%')
            ->value('max_suffix');

        return 'IRM-' . str_pad((string) ($maxSuffix + 1), 5, '0', STR_PAD_LEFT);
    }

    private function isAdmin(): bool
    {
        $user = auth()->user();

        return (bool) $user && $user->hasRole(['Super Admin', 'Admin']);
    }

    private function isIssueReturnManager(): bool
    {
        $user = auth()->user();

        return (bool) $user && $user->hasRole(['Super Admin', 'Admin', 'Procurement Manager', 'Procurement Manger']);
    }

    private function isProcurementManager(): bool
    {
        $user = auth()->user();

        return (bool) $user && $user->hasRole(['Procurement Manager', 'Procurement Manger']);
    }

    private function isSiteEngineer(): bool
    {
        $user = auth()->user();

        return (bool) $user && $user->hasRole(User::SITE_ENGINEER_ROLES);
    }

    private function isRestrictedToOwnTransactions(): bool
    {
        return $this->isSiteEngineer() || $this->isProcurementManager();
    }

    private function allowedWarehouseStatuses(): array
    {
        return [
            'Main Warehouse',
            'MainWarehouses',
            'Rans Warehouse',
            'Rans Warehouses',
            'General',
            'Directors',
        ];
    }

    private function canUseWarehouse(int $warehouseId): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($this->isSiteEngineer()) {
            return $user->manages($warehouseId);
        }

        if ($this->isProcurementManager()) {
            return Warehouse::ofAccount()->active()
                ->whereKey($warehouseId)
                ->whereIn('status', $this->allowedWarehouseStatuses())
                ->exists();
        }

        return true;
    }

    private function availableWarehouses()
    {
        $user = auth()->user();

        if ($this->isSiteEngineer()) {
            return $user->warehouses()
                ->where('active', 1)
                ->visible()
                ->orderBy('name')
                ->get(['warehouses.id as id', 'warehouses.name as name', 'warehouses.completed_at as completed_at']);
        }

        if ($this->isProcurementManager()) {
            return Warehouse::ofAccount()->active()->visible()
                ->whereIn('status', $this->allowedWarehouseStatuses())
                ->orderBy('name')
                ->get(['id', 'name', 'completed_at']);
        }

        return Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at']);
    }

    private function canRead(): bool
    {
        $user = auth()->user();

        if ($user && $user->hasRole('Store Manager')) {
            return false;
        }

        return (bool) $user && (
            $user->hasRole(['Super Admin', 'Admin', 'Procurement Manager', 'Procurement Manger', ...User::SITE_ENGINEER_ROLES]) ||
            $user->can('read-issue-returns')
        );
    }

    private function canCreate(): bool
    {
        $user = auth()->user();

        if ($user && $user->hasRole('Store Manager')) {
            return false;
        }

        return (bool) $user && (
            $user->hasRole(['Super Admin', 'Admin', 'Procurement Manager', 'Procurement Manger', ...User::SITE_ENGINEER_ROLES]) ||
            $user->can('create-issue-returns')
        );
    }

    private function canUpdate(IssueTransaction $issue): bool
    {
        $user = auth()->user();

        if ($user && $user->hasRole('Store Manager')) {
            return false;
        }

        return (bool) $user && (
            $user->hasRole(['Super Admin', 'Admin', 'Procurement Manager', 'Procurement Manger']) ||
            ($user->can('update-issue-returns') && ($user->id === (int) $issue->user_id || (bool) $user->edit_all))
        );
    }

    private function canDelete(): bool
    {
        $user = auth()->user();

        return (bool) $user && (
            $this->isIssueReturnManager() ||
            $user->can('delete-issue-returns')
        );
    }

    private function canPrint(): bool
    {
        $user = auth()->user();

        if ($user && $user->hasRole('Store Manager')) {
            return false;
        }

        return (bool) $user && ($this->isIssueReturnManager() || $user->can('print-issue-returns') || $user->can('read-issue-returns'));
    }
}
