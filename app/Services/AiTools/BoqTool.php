<?php

namespace App\Services\AiTools;

use App\Models\BoqMaterialCompliance;
use App\Models\Item;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BOQ (Bill of Quantities) compliance — issued-vs-planned quantity per item
 * per project. Formula and status thresholds mirror
 * ReportController::boqMaterialCompliance() exactly (that method's queries
 * stay `protected`/inline, so this reproduces them rather than reusing code
 * that isn't extracted for reuse).
 */
class BoqTool
{
    use ResolvesEntities;

    public function itemStatus(array $input, User $user): array
    {
        $warehouseName = $input['warehouse_name'] ?? null;
        $project = $this->resolveWarehouse($warehouseName);

        if (! $project) {
            return ['found' => false, 'message' => "No project matching \"{$warehouseName}\" was found."];
        }

        $scope = $this->resolveWarehouseScope($project);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that project.'];
        }

        $rows = $this->compileRows($project->id);

        $itemName = $input['item_name'] ?? null;

        if ($itemName) {
            $item = $this->resolveItem($itemName);

            if (! $item) {
                return ['found' => false, 'message' => "No item matching \"{$itemName}\" was found."];
            }

            $row = $rows->firstWhere('item_id', $item->id);

            if (! $row) {
                return ['found' => false, 'message' => "No BOQ or checkout data for {$item->name} on {$project->name}."];
            }

            return ['found' => true, 'project' => $project->name, 'item' => $row];
        }

        return [
            'found' => true,
            'project' => $project->name,
            'count' => $rows->count(),
            'status_summary' => $rows->groupBy('status')->map->count(),
        ];
    }

    public function overBudgetItems(array $input, User $user): array
    {
        $warehouseName = $input['warehouse_name'] ?? null;
        $project = $this->resolveWarehouse($warehouseName);

        if (! $project) {
            return ['found' => false, 'message' => "No project matching \"{$warehouseName}\" was found."];
        }

        $scope = $this->resolveWarehouseScope($project);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that project.'];
        }

        $rows = $this->compileRows($project->id)
            ->whereIn('status', ['Slight Overuse', 'Overused'])
            ->sortByDesc('variance_quantity')
            ->values();

        return [
            'found' => true,
            'project' => $project->name,
            'count' => $rows->count(),
            'items' => $rows->all(),
        ];
    }

    protected function compileRows(int $projectWarehouseId): Collection
    {
        $issuedRows = DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->where('checkouts.account_id', getAccountId())
            ->whereNull('checkouts.deleted_at')
            ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
            ->where('checkouts.warehouse_id', $projectWarehouseId)
            ->groupBy('checkout_items.item_id', 'items.name')
            ->select(
                'checkout_items.item_id',
                'items.name as item_name',
                DB::raw('SUM(checkout_items.quantity) as issued_quantity'),
                DB::raw('SUM(checkout_items.quantity * COALESCE(checkout_items.price, items.price, 0)) as issued_cost')
            )
            ->get()
            ->keyBy('item_id');

        $savedBoq = BoqMaterialCompliance::ofAccount()
            ->where('project_warehouse_id', $projectWarehouseId)
            ->get(['item_id', 'boq_quantity', 'unit_price'])
            ->keyBy('item_id');

        $itemIds = $issuedRows->keys()->merge($savedBoq->keys())->unique()->values();

        if ($itemIds->isEmpty()) {
            return collect();
        }

        $itemMeta = Item::whereIn('id', $itemIds)->get(['id', 'name', 'price'])->keyBy('id');

        return $itemIds->map(function ($itemId) use ($itemMeta, $issuedRows, $savedBoq) {
            $item = $itemMeta->get($itemId);
            $issued = $issuedRows->get($itemId);
            $saved = $savedBoq->get($itemId);

            $issuedQty = (float) ($issued->issued_quantity ?? 0);
            $issuedCost = (float) ($issued->issued_cost ?? 0);
            $boqQty = (float) ($saved->boq_quantity ?? 0);

            $derivedUnitPrice = $issuedQty > 0 ? ($issuedCost / $issuedQty) : (float) ($item->price ?? 0);
            $unitPrice = (float) ($saved->unit_price ?? $derivedUnitPrice);

            $status = 'Not Set';

            if ($boqQty > 0) {
                $ratio = $issuedQty / $boqQty;

                if ($ratio < 0.80) {
                    $status = 'Under Usage';
                } elseif ($ratio <= 1.00) {
                    $status = 'On Track';
                } elseif ($ratio <= 1.10) {
                    $status = 'Slight Overuse';
                } else {
                    $status = 'Overused';
                }
            }

            return [
                'item_id' => (int) $itemId,
                'item_name' => (string) ($item->name ?? ('Item #' . $itemId)),
                'issued_quantity' => $issuedQty,
                'boq_quantity' => $boqQty,
                'variance_quantity' => $issuedQty - $boqQty,
                'unit_price' => $unitPrice,
                'status' => $status,
            ];
        });
    }
}
