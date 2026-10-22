<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReturnMaterialRequest;
use App\Http\Resources\ReturnMaterialCollection;
use App\Models\IssueReturn;
use App\Models\IssueReturnItem;
use App\Models\IssueTransaction;
use App\Models\IssueTransactionItem;
use App\Models\Item;
use App\Models\Stock;
use App\Models\User;
use App\Services\IssueReturnLogService;
use App\Services\IssueReturnNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use App\Traits\GuardsCompletedWarehouse;

class ReturnMaterialController extends Controller
{
    use GuardsCompletedWarehouse;

    public function __construct()
    {
        // This module uses explicit role/permission guards per action.
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($this->canReturn(), 403);

        $filters = $request->all('search', 'warehouse_id', 'status');
        $returnedQtySubquery = "(
            SELECT COALESCE(SUM(iti.returned_quantity), 0)
            FROM issue_transaction_items iti
            WHERE iti.issue_transaction_id = issue_transactions.id
              AND iti.deleted_at IS NULL
        )";
        $balanceQtySubquery = "(
            SELECT COALESCE(SUM(iti.balance_quantity), 0)
            FROM issue_transaction_items iti
            WHERE iti.issue_transaction_id = issue_transactions.id
              AND iti.deleted_at IS NULL
        )";
        $statusCase = "CASE
            WHEN {$returnedQtySubquery} > 0 AND {$balanceQtySubquery} <= 0 THEN 'fully_returned'
            WHEN {$returnedQtySubquery} > 0 THEN 'partially_returned'
            ELSE 'open'
        END";

        return Inertia::render('IssueReturn/ReturnIndex', [
            'filters' => $filters,
            'issues' => new ReturnMaterialCollection(
                IssueTransaction::query()
                    ->with(['warehouse:id,name', 'items:id,issue_transaction_id,item_id,balance_quantity', 'items.item:id,name,code'])
                    ->when($this->isRestrictedToOwnTransactions(), fn ($q) => $q->where('user_id', auth()->id()))
                    ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(function ($sq) use ($v) {
                        $sq->where('issue_number', 'like', "%{$v}%")
                            ->orWhere('issued_to', 'like', "%{$v}%")
                            ->orWhere('project', 'like', "%{$v}%");
                    }))
                    ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
                    ->when($filters['status'] ?? null, fn ($q, $v) => $q->whereRaw("{$statusCase} = ?", [$v]))
                    ->select('issue_transactions.*')
                    ->selectRaw("{$balanceQtySubquery} as computed_balance_quantity")
                    ->selectRaw("{$statusCase} as computed_status")
                    ->orderByDesc('id')
                    ->paginate()
                    ->withQueryString()
            ),
        ]);
    }

    public function create(IssueTransaction $issueMaterial)
    {
        abort_unless($this->canReturn(), 403);
        abort_if($this->isRestrictedToOwnTransactions() && (int) $issueMaterial->user_id !== (int) auth()->id(), 403);
        if ($response = $this->rejectIfWarehouseBlocked($issueMaterial->warehouse_id)) {
            return $response;
        }

        if ($this->issueBalanceQuantity($issueMaterial) <= 0) {
            return redirect()->route('return-materials.index')
                ->with('error', 'This issue is already fully returned.');
        }

        $issueMaterial->load([
            'warehouse:id,name',
            'user:id,name',
            'items.item:id,name,code',
            'items.unit:id,code,name',
            'returns.items',
        ]);

        return Inertia::render('IssueReturn/ReturnForm', [
            'issue' => $issueMaterial,
        ]);
    }

    public function store(ReturnMaterialRequest $request, IssueTransaction $issueMaterial)
    {
        abort_unless($this->canReturn(), 403);
        abort_if($this->isRestrictedToOwnTransactions() && (int) $issueMaterial->user_id !== (int) auth()->id(), 403);
        if ($response = $this->rejectIfWarehouseBlocked($issueMaterial->warehouse_id)) {
            return $response;
        }

        if ($this->issueBalanceQuantity($issueMaterial) <= 0) {
            return back()->with('error', 'This issue is already fully returned.');
        }

        $data = $request->validated();

        DB::transaction(function () use ($data, $issueMaterial, $request) {
            $issueMaterial->load('items');

            $return = IssueReturn::query()->create([
                'issue_transaction_id' => $issueMaterial->id,
                'return_date' => $data['return_date'],
                'returned_by' => $data['returned_by'],
                'received_by' => $data['received_by'],
                'contact_number' => $data['contact_number'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $hasLine = false;

            foreach ($data['items'] as $line) {
                if (empty($line['selected'])) {
                    continue;
                }

                $issueItem = IssueTransactionItem::query()
                    ->where('issue_transaction_id', $issueMaterial->id)
                    ->where('id', $line['issue_transaction_item_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $issueItem) {
                    continue;
                }

                $qty = (float) ($line['returning_qty'] ?? 0);
                $condition = strtolower((string) ($line['condition'] ?? 'good'));

                if ($qty <= 0) {
                    continue;
                }

                if ($qty > (float) $issueItem->balance_quantity) {
                    throw ValidationException::withMessages([
                        'items' => 'Cannot return more than balance quantity.',
                    ]);
                }

                $hasLine = true;

                IssueReturnItem::query()->create([
                    'issue_return_id' => $return->id,
                    'issue_transaction_item_id' => $issueItem->id,
                    'item_id' => $issueItem->item_id,
                    'unit_id' => $issueItem->unit_id,
                    'issued_qty' => (float) $issueItem->issued_quantity,
                    'previously_returned_qty' => (float) $issueItem->returned_quantity,
                    'balance_qty' => (float) $issueItem->balance_quantity,
                    'returning_qty' => $qty,
                    'condition' => in_array($condition, ['good', 'damaged', 'lost']) ? $condition : 'good',
                    'remarks' => $line['remarks'] ?? null,
                ]);

                $issueItem->returned_quantity = (float) $issueItem->returned_quantity + $qty;
                $issueItem->balance_quantity = max(0, (float) $issueItem->issued_quantity - (float) $issueItem->returned_quantity);
                if ($condition === 'damaged') {
                    $issueItem->damaged_quantity = (float) $issueItem->damaged_quantity + $qty;
                }
                if ($condition === 'lost') {
                    $issueItem->lost_quantity = (float) $issueItem->lost_quantity + $qty;
                }
                $issueItem->save();

                if ($condition !== 'lost') {
                    $this->increaseStock($issueMaterial->warehouse_id, $issueItem->item_id, $qty, $issueItem->unit_id, 'Return Material (' . ucfirst($condition) . ')');
                }
            }

            if (! $hasLine) {
                throw ValidationException::withMessages([
                    'items' => 'Please select at least one item to return.',
                ]);
            }

            if ($request->file('attachments')) {
                $return->saveAttachments($request->file('attachments'));
                IssueReturnLogService::write('Upload Attachment', $issueMaterial, $return, 'Return attachments uploaded.');
            }

            $issueMaterial->refreshTotalsAndStatus();

            IssueReturnLogService::write('Return Item', $issueMaterial, $return, 'Return posted for issue transaction.');
            IssueReturnNotificationService::notifyReturn($issueMaterial->fresh('items'), $return->fresh('items'));
        });

        return redirect()->route('issue-materials.show', $issueMaterial->id)->with('message', 'Return saved successfully.');
    }

    private function canReturn(): bool
    {
        $user = auth()->user();

        if ($user && $user->hasRole('Store Manager')) {
            return false;
        }

        return (bool) $user && (
            $user->hasRole(['Super Admin', 'Admin', 'Procurement Manager', 'Procurement Manger', ...User::SITE_ENGINEER_ROLES]) ||
            $user->can('return-issue-returns') ||
            $user->can('create-issue-returns')
        );
    }

    private function isSiteEngineer(): bool
    {
        $user = auth()->user();

        return (bool) $user && $user->hasRole(User::SITE_ENGINEER_ROLES);
    }

    private function isProcurementManager(): bool
    {
        $user = auth()->user();

        return (bool) $user && $user->hasRole(['Procurement Manager', 'Procurement Manger']);
    }

    private function isRestrictedToOwnTransactions(): bool
    {
        return $this->isSiteEngineer() || $this->isProcurementManager();
    }

    private function issueBalanceQuantity(IssueTransaction $issue): float
    {
        return (float) IssueTransactionItem::query()
            ->where('issue_transaction_id', $issue->id)
            ->whereNull('deleted_at')
            ->sum('balance_quantity');
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
}
