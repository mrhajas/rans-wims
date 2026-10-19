<?php

namespace App\Actions\Tec;

use App\Events\TransferEvent;
use App\Models\Stock;
use App\Models\Transfer;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class CleanupWarehouse
{
    public function __invoke(Warehouse $warehouse): array
    {
        $sources = $this->aggregateIncomingTransfers($warehouse->id);
        $plan    = $this->buildReturnPlan($sources, $warehouse->id);

        return $this->createTransfers($plan, $warehouse, 'Auto-return from cleanup of');
    }

    /**
     * Create transfers for remaining stock using user-chosen destinations
     * (instead of the auto-computed original-source plan). Used when the
     * remaining items have no transfer history to return to (e.g. items
     * checked in directly at this warehouse rather than transferred in).
     *
     * @param  array<int, array{item_id:int, quantity:float, unit_id:?int, to_warehouse_id:int}>  $items
     */
    public function createManualTransfers(Warehouse $warehouse, array $items): array
    {
        $plan = [];

        foreach ($items as $item) {
            $toWarehouseId = (int) $item['to_warehouse_id'];
            if ($toWarehouseId === $warehouse->id) {
                continue;
            }

            $stock = Stock::main()->ofItem($item['item_id'])->ofWarehouse($warehouse->id)->first();
            $available = (float) ($stock->quantity ?? 0);
            $quantity = min((float) $item['quantity'], $available);

            if ($quantity <= 0) {
                continue;
            }

            $plan[$toWarehouseId][] = [
                'item_id'  => (int) $item['item_id'],
                'quantity' => $quantity,
                'unit_id'  => $item['unit_id'] ?? null,
            ];
        }

        return $this->createTransfers($plan, $warehouse, 'Manual distribution from cleanup of');
    }

    public function preview(Warehouse $warehouse): array
    {
        $sources = $this->aggregateIncomingTransfers($warehouse->id);
        $plan    = $this->buildReturnPlan($sources, $warehouse->id);
        $stockItems = Stock::main()->ofWarehouse($warehouse->id)->where('quantity', '>', 0)
            ->with('item.unit')
            ->get();

        return $this->buildRemainingStockPreview($stockItems, $plan);
    }

    public function buildRemainingStockPreview(iterable $stockItems, array $plan): array
    {
        $remaining = [];

        foreach ($stockItems as $stock) {
            $returnedQuantity = 0.0;
            foreach ($plan as $items) {
                foreach ($items as $item) {
                    if (($item['item_id'] ?? null) == $stock->item_id) {
                        $returnedQuantity += (float) ($item['quantity'] ?? 0);
                    }
                }
            }

            $remainingQuantity = max(0.0, (float) ($stock->quantity ?? 0) - $returnedQuantity);
            if ($remainingQuantity <= 0) {
                continue;
            }

            $remaining[] = [
                'item_id' => (int) $stock->item_id,
                'name' => optional($stock->item)->name ?? '',
                'code' => optional($stock->item)->code ?? '',
                'quantity' => $remainingQuantity,
                'unit' => optional(optional($stock->item)->unit)->name ?? optional(optional($stock->item)->unit)->code ?? '',
                'unit_id' => optional($stock->item)->unit_id,
            ];
        }

        usort($remaining, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $remaining;
    }

    private function aggregateIncomingTransfers(int $warehouseId)
    {
        return DB::table('transfer_items')
            ->join('transfers', 'transfers.id', '=', 'transfer_items.transfer_id')
            ->join('items', 'items.id', '=', 'transfer_items.item_id')
            ->leftJoin('units', 'units.id', '=', 'transfer_items.unit_id')
            ->where('transfer_items.to_warehouse_id', $warehouseId)
            ->where('transfer_items.from_warehouse_id', '!=', $warehouseId)
            ->whereNull('transfer_items.deleted_at')
            ->whereNull('transfers.deleted_at')
            ->where('transfers.draft', 0)
            ->where('items.has_variants', 0)
            ->select(
                'transfer_items.item_id',
                'transfer_items.from_warehouse_id',
                'items.unit_id as base_unit_id',
                DB::raw("SUM(
                    CASE
                        WHEN units.operator = '*' THEN transfer_items.quantity * units.operation_value
                        WHEN units.operator = '/' THEN transfer_items.quantity / units.operation_value
                        WHEN units.operator = '+' THEN transfer_items.quantity + units.operation_value
                        WHEN units.operator = '-' THEN transfer_items.quantity - units.operation_value
                        ELSE transfer_items.quantity
                    END
                ) as base_quantity")
            )
            ->groupBy('transfer_items.item_id', 'transfer_items.from_warehouse_id', 'items.unit_id')
            ->get();
    }

    private function buildReturnPlan($sources, int $warehouseId): array
    {
        $plan = [];

        foreach ($sources->groupBy('item_id') as $itemId => $rows) {
            $rows = $rows->values();
            $totalTransferred = (float) $rows->sum('base_quantity');
            if ($totalTransferred <= 0) {
                continue;
            }

            $stock = Stock::main()->ofItem($itemId)->ofWarehouse($warehouseId)->first();
            $currentStock = (float) ($stock->quantity ?? 0);
            if ($currentStock <= 0) {
                continue;
            }

            // Cap return at what was actually transferred in (don't return stock that came from check-ins, etc.)
            $amountToReturn = min($currentStock, $totalTransferred);
            $baseUnitId = $rows->first()->base_unit_id;
            $count = $rows->count();
            $allocated = 0.0;

            foreach ($rows as $i => $row) {
                $portion = $i === $count - 1
                    ? $amountToReturn - $allocated
                    : round($amountToReturn * ($row->base_quantity / $totalTransferred), 4);

                if ($portion <= 0) {
                    continue;
                }
                $allocated += $portion;

                $plan[$row->from_warehouse_id][] = [
                    'item_id'  => $itemId,
                    'quantity' => $portion,
                    'unit_id'  => $baseUnitId,
                ];
            }
        }

        return $plan;
    }

    private function createTransfers(array $plan, Warehouse $warehouse, string $reason): array
    {
        $created = [];
        if (empty($plan)) {
            log_activity(
                'Cleanup of warehouse "' . $warehouse->name . '", no remaining stock to distribute.',
                ['warehouse_id' => $warehouse->id, 'warehouse_name' => $warehouse->name],
                $warehouse,
                'Warehouse'
            );

            return $created;
        }

        DB::transaction(function () use (&$created, $plan, $warehouse, $reason) {
            $counter = $this->startingCounter();

            foreach ($plan as $toWarehouseId => $items) {
                if (empty($items)) {
                    continue;
                }

                $reference = 'RST-' . str_pad((string) $counter++, 4, '0', STR_PAD_LEFT);

                $transfer = Transfer::create([
                    'date'              => now()->toDateString(),
                    'reference'         => $reference,
                    'draft'             => 0,
                    'from_warehouse_id' => $warehouse->id,
                    'to_warehouse_id'   => $toWarehouseId,
                    'details'           => $reason . ' ' . $warehouse->name,
                ]);

                foreach ($items as $item) {
                    $transfer->items()->create([
                        'item_id'           => $item['item_id'],
                        'quantity'          => $item['quantity'],
                        'unit_id'           => $item['unit_id'],
                        'from_warehouse_id' => $warehouse->id,
                        'to_warehouse_id'   => $toWarehouseId,
                        'draft'             => 0,
                    ]);
                }

                $transfer->load(['items.item', 'items.unit', 'items.variations', 'fromWarehouse', 'toWarehouse']);
                event(new TransferEvent($transfer, 'created'));

                log_activity(
                    'Cleanup transfer ' . $transfer->reference
                        . ' created from "' . $warehouse->name
                        . '" to "' . optional($transfer->toWarehouse)->name . '" ('
                        . count($items) . ' item' . (count($items) === 1 ? '' : 's') . ').',
                    [
                        'cleanup_of_warehouse_id'   => $warehouse->id,
                        'cleanup_of_warehouse_name' => $warehouse->name,
                        'transfer_id'               => $transfer->id,
                        'transfer_reference'        => $transfer->reference,
                        'to_warehouse_id'           => $toWarehouseId,
                        'item_count'                => count($items),
                    ],
                    $transfer,
                    'Transfer'
                );

                $created[] = $transfer;
            }

            log_activity(
                'Warehouse "' . $warehouse->name . '" cleanup completed - '
                    . count($created) . ' transfer' . (count($created) === 1 ? '' : 's') . ' created.',
                [
                    'warehouse_id'    => $warehouse->id,
                    'warehouse_name'  => $warehouse->name,
                    'transfer_count'  => count($created),
                    'transfer_ids'    => array_map(fn ($t) => $t->id, $created),
                    'references'      => array_map(fn ($t) => $t->reference, $created),
                ],
                $warehouse,
                'Warehouse'
            );
        }, 2);

        return $created;
    }

    private function startingCounter(): int
    {
        $last = Transfer::withTrashed()->latest('id')->first();

        return $last && preg_match('/RST-(\d+)/', $last->reference, $m)
            ? ((int) $m[1] + 1)
            : 1;
    }
}
