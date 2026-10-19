<?php

namespace App\Services\AiTools;

use App\Models\Stock;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;

class StockTool
{
    use ResolvesEntities;

    public function lookup(array $input, User $user): array
    {
        $itemName = $input['item_name'] ?? null;
        $item = $this->resolveItem($itemName);

        if (! $item) {
            return ['found' => false, 'message' => "No item matching \"{$itemName}\" was found."];
        }

        $warehouse = $this->resolveWarehouse($input['warehouse_name'] ?? null);
        $scope = $this->resolveWarehouseScope($warehouse);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
        }

        $query = Stock::ofAccount()->ofItem($item->id)->main();

        if ($scope['ids'] !== null) {
            $query->whereIn('warehouse_id', $scope['ids']);
        }

        return [
            'found' => true,
            'item' => $item->name,
            'unit' => optional($item->unit)->name,
            'warehouse' => $warehouse->name ?? 'All warehouses (company-wide)',
            'quantity' => (float) $query->sum('quantity'),
        ];
    }

    public function lowStock(array $input, User $user): array
    {
        $warehouse = $this->resolveWarehouse($input['warehouse_name'] ?? null);
        $scope = $this->resolveWarehouseScope($warehouse);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
        }

        $query = Stock::ofAccount()->main()
            ->whereColumn('quantity', '<=', 'alert_quantity')
            ->where('alert_quantity', '>', 0)
            ->with('item:id,name');

        if ($scope['ids'] !== null) {
            $query->whereIn('warehouse_id', $scope['ids']);
        }

        $rows = $query->limit(25)->get()->map(fn ($stock) => [
            'item' => optional($stock->item)->name,
            'warehouse' => optional($stock->warehouse)->name,
            'quantity' => (float) $stock->quantity,
            'alert_quantity' => (float) $stock->alert_quantity,
        ])->values()->all();

        return ['found' => true, 'count' => count($rows), 'items' => $rows];
    }
}
