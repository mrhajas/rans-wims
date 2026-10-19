<?php

namespace App\Listeners;

use App\Events\MaterialReallocationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Unlike Checkin/Checkout/Transfer/Adjustment, this listener never moves stock
 * on create/update/delete/restore — Material Reallocation stock only moves once,
 * at Final Approval, using the Store-Manager-verified received_quantity per
 * item/destination row (not the originally requested or allocated quantity).
 */
class MaterialReallocationEventListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function failed(MaterialReallocationEvent $event, $exception)
    {
        Log::error('MaterialReallocationEvent failed!', ['Error' => $exception, 'MaterialReallocation' => $event->materialReallocation]);
    }

    public function handle(MaterialReallocationEvent $event)
    {
        if ($event->method === 'finalApproved') {
            $this->moveStock($event->materialReallocation);
        }
    }

    private function moveStock($materialReallocation): void
    {
        // All-or-nothing: a failure midway must not leave the source deducted but the
        // destination not yet credited (and a queue retry must not double-apply).
        DB::transaction(function () use ($materialReallocation) {
            $this->applyMovements($materialReallocation);
        });
    }

    private function applyMovements($materialReallocation): void
    {
        foreach ($materialReallocation->items as $reallocationItem) {
            $item = $reallocationItem->item;

            if (! $item || ! $item->track_quantity) {
                continue;
            }

            $receivedQuantity = (float) ($reallocationItem->received_quantity ?? 0);

            if ($receivedQuantity <= 0 || ! $reallocationItem->destination_warehouse_id) {
                continue;
            }

            $baseQuantity = convert_to_base_quantity($receivedQuantity, $reallocationItem->unit);

            $this->applyDelta($item, $reallocationItem, $materialReallocation->source_warehouse_id, -$baseQuantity, $materialReallocation->reference, 'Source Deduction');
            $this->applyDelta($item, $reallocationItem, $reallocationItem->destination_warehouse_id, $baseQuantity, $materialReallocation->reference, 'Destination Receipt');
        }
    }

    private function applyDelta($item, $reallocationItem, $warehouseId, $delta, $reference, $trailLabel): void
    {
        // Row lock so a concurrent issue/checkout/reallocation can't read-modify-write the same stock row.
        $stock = $item->stock()->ofWarehouse($warehouseId)->lockForUpdate()->first();

        if ($stock) {
            $newQuantity = $stock->quantity + $delta;
            if ($newQuantity < 0) {
                Log::warning('Material Reallocation drove stock negative', ['reference' => $reference, 'item_id' => $item->id, 'warehouse_id' => $warehouseId, 'resulting_quantity' => $newQuantity]);
            }
            $stock->update(['quantity' => $newQuantity]);
        } else {
            $newQuantity = $delta;
            $stock = $item->stock()->create([
                'variation_id' => null,
                'quantity' => $newQuantity,
                'account_id' => getAccountId(),
                'warehouse_id' => $warehouseId,
                'rack_location' => $item->rack_location,
                'alert_quantity' => $item->alert_quantity,
            ]);
        }

        $item->stockTrails()->create([
            'variation_id' => null,
            'quantity' => $newQuantity,
            'item_id' => $item->id,
            'unit_id' => $reallocationItem->unit_id,
            'warehouse_id' => $warehouseId,
            'type' => "Material Reallocation {$reference} - {$trailLabel}",
        ])->referencesObject($reallocationItem);
    }
}
