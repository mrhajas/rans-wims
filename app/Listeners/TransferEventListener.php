<?php

namespace App\Listeners;

use App\Events\TransferEvent;
use App\Services\MainWarehouseEmailService;
use App\Services\TransferNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class TransferEventListener implements ShouldQueue
{
    use InteractsWithQueue;

    public $track_weight;

    public $transfer;

    public function failed(TransferEvent $event, $exception)
    {
        Log::error('TransferEvent failed!', ['Error' => $exception, 'Transfer' => $event->transfer]);
    }

    public function handle(TransferEvent $event)
    {
        $this->track_weight = get_settings('track_weight');
        $this->{$event->method}($event);
    }

    private function created(TransferEvent $event)
    {
        // Transfers Notification page: "Create Transfer" (drafts and transfers waiting for approval included); nothing was sent for this before
        TransferNotifier::fire('created', $event->transfer, __('Transfer Created'), $event->transfer->reference . ' was created: ' . TransferNotifier::route($event->transfer) . '.', auth()->id() ?: $event->transfer->user_id);

        if (! $event->transfer->draft) {
            $this->setStock($event->transfer);
            $this->notifyTransferDone($event->transfer);
        }
    }

    /** A main-warehouse transfer was approved: this is the moment stock actually moves (it was held back as a draft). */
    private function approved(TransferEvent $event)
    {
        $this->setStock($event->transfer);
        $this->notifyTransferDone($event->transfer);
    }

    private function deleted(TransferEvent $event)
    {
        if (! $event->transfer->draft) {
            $this->setStock($event->transfer, true);
        }

        // Transfers workflow page (Who Gets Notified / Messages). "Deleted for good" is its own event; while it is switched off the older
        // "Transfer Deleted" notice is sent for a permanent delete too, exactly as before.
        if ($event->permanent && \App\Services\WorkflowGate::on('transfers', 'permanently_deleted', (int) ($event->transfer->account_id ?? 1))) {
            TransferNotifier::fire('permanently_deleted', $event->transfer, __('Transfer Deleted for Good'), $event->transfer->reference . ' was deleted for good' . (auth()->user() ? ' by ' . auth()->user()->name : '') . '.', auth()->id(), ['url' => route('transfers.index'), 'skip_actor' => true]);

            return;
        }
        TransferNotifier::fire('deleted', $event->transfer, __('Transfer Deleted'), $event->transfer->reference . ' was deleted.', auth()->id(), ['url' => route('deleted.index', ['type' => 'transfers'])]);
    }

    private function restored(TransferEvent $event)
    {
        TransferNotifier::fire('restored', $event->transfer, __('Transfer Restored'), $event->transfer->reference . ' was restored from the deleted records.', auth()->id());

        if (! $event->transfer->draft) {
            $this->setStock($event->transfer);
        }
    }

    private function setStock($transfer, $moveBack = false)
    {
        foreach ($transfer->items as $item) {
            if ($item->item->track_quantity || $item->item->track_weight) {
                $fromStock = $item->stock()->main()->ofWarehouse($transfer->from_warehouse_id)->first();
                if ($fromStock) {
                    $weight = $fromStock->weight;
                    $quantity = $fromStock->quantity;
                    if ($item->item->track_quantity) {
                        $base_quantity = convert_to_base_quantity($item->quantity, $item->unit);
                        $quantity = $moveBack ? $fromStock->quantity + $base_quantity : $fromStock->quantity - $base_quantity;
                    }
                    if ($this->track_weight && $item->item->track_weight) {
                        $weight = $moveBack ? $fromStock->weight + $item->weight : $fromStock->weight - $item->weight;
                    }
                    if ($fromStock->quantity != $quantity || $fromStock->weight != $weight) {
                        $fromStock->update(['quantity' => $quantity, 'weight' => $weight]);
                        // event(new \App\Events\StockEvent($item, $quantity, $weight, null, $transfer->from_warehouse_id));
                        $item->item->stockTrails()->create([
                            'variation_id' => null,
                            'weight'       => $weight,
                            'quantity'     => $quantity,
                            'item_id'      => $item->item_id,
                            'unit_id'      => $item->unit_id,
                            'warehouse_id' => $transfer->from_warehouse_id,
                            'type'         => ($moveBack ? 'Editing' : 'Updating') . ' Transfer Item',
                        ])->referencesObject($item);
                    }
                } else {
                    $weight = 0;
                    $quantity = 0;
                    if ($item->item->track_quantity) {
                        $base_quantity = convert_to_base_quantity($item->quantity, $item->unit);
                        $quantity = $moveBack ? $base_quantity : 0 - $base_quantity;
                    }
                    if ($this->track_weight && $item->item->track_weight) {
                        $weight = $moveBack ? $item->weight : 0 - $item->weight;
                    }
                    $item->item->stock()->create([
                        'variation_id'   => null,
                        'weight'         => $weight,
                        'quantity'       => $quantity,
                        'account_id'     => getAccountId(),
                        'rack_location'  => $item->item->rack_location,
                        'alert_quantity' => $item->item->alert_quantity,
                        'warehouse_id'   => $transfer->from_warehouse_id,
                    ]);
                    $item->item->stockTrails()->create([
                        'variation_id' => null,
                        'weight'       => $weight,
                        'quantity'     => $quantity,
                        'item_id'      => $item->item_id,
                        'unit_id'      => $item->unit_id,
                        'warehouse_id' => $transfer->from_warehouse_id,
                        'type'         => ($moveBack ? 'Editing' : 'Updating') . ' Transfer Item',
                    ])->referencesObject($item);
                }
                if ($item->variations && $item->variations->isNotEmpty()) {
                    foreach ($item->variations as $variation) {
                        $variationStock = $variation->stock()->ofWarehouse($transfer->from_warehouse_id)->first();
                        if ($variationStock) {
                            $weight = $variationStock->weight;
                            $quantity = $variationStock->quantity;
                            if ($item->item->track_quantity) {
                                $base_quantity = convert_to_base_quantity($variation->pivot->quantity, $variation->unit);
                                $quantity = $moveBack ? $variationStock->quantity + $base_quantity : $variationStock->quantity - $base_quantity;
                            }
                            if ($this->track_weight && $item->item->track_weight) {
                                $weight = $moveBack ? $variationStock->weight + $variation->pivot->weight : $variationStock->weight - $variation->pivot->weight;
                            }
                            if ($variationStock->quantity != $quantity || $variationStock->weight != $weight) {
                                $variationStock->update(['quantity' => $quantity, 'weight' => $weight]);
                                // event(new \App\Events\StockEvent($item, $quantity, $weight, $variation->id, $transfer->from_warehouse_id));
                                $item->item->stockTrails()->create([
                                    'weight'       => $weight,
                                    'quantity'     => $quantity,
                                    'item_id'      => $item->item_id,
                                    'unit_id'      => $item->unit_id,
                                    'variation_id' => $variation->id,
                                    'warehouse_id' => $transfer->from_warehouse_id,
                                    'type'         => ($moveBack ? 'Editing' : 'Updating') . ' Transfer Item',
                                ])->referencesObject($item);
                            }
                        } else {
                            $weight = 0;
                            $quantity = 0;
                            if ($item->item->track_quantity) {
                                $base_quantity = convert_to_base_quantity($variation->pivot->quantity, $variation->unit);
                                $quantity = $moveBack ? $base_quantity : 0 - $base_quantity;
                            }
                            if ($this->track_weight && $item->item->track_weight) {
                                $weight = $moveBack ? $variation->pivot->weight : 0 - $variation->pivot->weight;
                            }
                            $variation->stock()->create([
                                'weight'         => $weight,
                                'quantity'       => $quantity,
                                'account_id'     => getAccountId(),
                                'item_id'        => $item->item_id,
                                'rack_location'  => $item->item->rack_location,
                                'alert_quantity' => $item->item->alert_quantity,
                                'warehouse_id'   => $transfer->from_warehouse_id,
                            ]);
                            $item->item->stockTrails()->create([
                                'weight'       => $weight,
                                'quantity'     => $quantity,
                                'item_id'      => $item->item_id,
                                'unit_id'      => $item->unit_id,
                                'variation_id' => $variation->id,
                                'warehouse_id' => $transfer->from_warehouse_id,
                                'type'         => ($moveBack ? 'Editing' : 'Updating') . ' Transfer Item',
                            ])->referencesObject($item);
                        }
                    }
                }

                $toStock = $item->stock()->main()->ofWarehouse($transfer->to_warehouse_id)->first();
                if ($toStock) {
                    $weight = $toStock->weight;
                    $quantity = $toStock->quantity;
                    if ($item->item->track_quantity) {
                        $base_quantity = convert_to_base_quantity($item->quantity, $item->unit);
                        $quantity = $moveBack ? $toStock->quantity - $base_quantity : $toStock->quantity + $base_quantity;
                    }
                    if ($this->track_weight && $item->item->track_weight) {
                        $weight = $moveBack ? $toStock->weight - $item->weight : $toStock->weight + $item->weight;
                    }
                    if ($toStock->quantity != $quantity || $toStock->weight != $weight) {
                        $toStock->update(['quantity' => $quantity, 'weight' => $weight]);
                        // event(new \App\Events\StockEvent($item, $quantity, $weight, null, $transfer->to_warehouse_id));
                        $item->item->stockTrails()->create([
                            'variation_id' => null,
                            'weight'       => $weight,
                            'quantity'     => $quantity,
                            'item_id'      => $item->item_id,
                            'unit_id'      => $item->unit_id,
                            'warehouse_id' => $transfer->to_warehouse_id,
                            'type'         => ($moveBack ? 'Editing' : 'Updating') . ' Transfer Item',
                        ])->referencesObject($item);
                    }
                } else {
                    $weight = 0;
                    $quantity = 0;
                    if ($item->item->track_quantity) {
                        $base_quantity = convert_to_base_quantity($item->quantity, $item->unit);
                        $quantity = $moveBack ? 0 - $base_quantity : $base_quantity;
                    }
                    if ($this->track_weight && $item->item->track_weight) {
                        $weight = $moveBack ? 0 - $item->weight : $item->weight;
                    }
                    $item->item->stock()->create([
                        'variation_id'   => null,
                        'weight'         => $weight,
                        'quantity'       => $quantity,
                        'account_id'     => getAccountId(),
                        'warehouse_id'   => $transfer->to_warehouse_id,
                        'rack_location'  => $item->item->rack_location,
                        'alert_quantity' => $item->item->alert_quantity,
                    ]);
                    $item->item->stockTrails()->create([
                        'variation_id' => null,
                        'weight'       => $weight,
                        'quantity'     => $quantity,
                        'item_id'      => $item->item_id,
                        'unit_id'      => $item->unit_id,
                        'warehouse_id' => $transfer->to_warehouse_id,
                        'type'         => ($moveBack ? 'Editing' : 'Updating') . ' Transfer Item',
                    ])->referencesObject($item);
                }
                if ($item->variations && $item->variations->isNotEmpty()) {
                    foreach ($item->variations as $variation) {
                        $variationStock = $variation->stock()->ofWarehouse($transfer->to_warehouse_id)->first();
                        if ($variationStock) {
                            $weight = $variationStock->weight;
                            $quantity = $variationStock->quantity;
                            if ($item->item->track_quantity) {
                                $base_quantity = convert_to_base_quantity($variation->pivot->quantity, $variation->unit);
                                $quantity = $moveBack ? $variationStock->quantity - $base_quantity : $variationStock->quantity + $base_quantity;
                            }
                            if ($this->track_weight && $item->item->track_weight) {
                                $weight = $moveBack ? $variationStock->weight - $variation->pivot->weight : $variationStock->weight + $variation->pivot->weight;
                            }
                            if ($variationStock->quantity != $quantity || $variationStock->weight != $weight) {
                                $variationStock->update(['quantity' => $quantity, 'weight' => $weight]);
                                // event(new \App\Events\StockEvent($item, $quantity, $weight, $variation->id, $transfer->to_warehouse_id));
                                $item->item->stockTrails()->create([
                                    'weight'       => $weight,
                                    'quantity'     => $quantity,
                                    'item_id'      => $item->item_id,
                                    'unit_id'      => $item->unit_id,
                                    'variation_id' => $variation->id,
                                    'warehouse_id' => $transfer->to_warehouse_id,
                                    'type'         => ($moveBack ? 'Editing' : 'Updating') . ' Transfer Item',
                                ])->referencesObject($item);
                            }
                        } else {
                            $weight = 0;
                            $quantity = 0;
                            if ($item->item->track_quantity) {
                                $base_quantity = convert_to_base_quantity($variation->pivot->quantity, $variation->unit);
                                $quantity = $moveBack ? 0 - $base_quantity : $base_quantity;
                            }
                            if ($this->track_weight && $item->item->track_weight) {
                                $weight = $moveBack ? 0 - $variation->pivot->weight : $variation->pivot->weight;
                            }
                            $variation->stock()->create([
                                'weight'         => $weight,
                                'quantity'       => $quantity,
                                'account_id'     => getAccountId(),
                                'item_id'        => $item->item_id,
                                'warehouse_id'   => $transfer->to_warehouse_id,
                                'rack_location'  => $item->item->rack_location,
                                'alert_quantity' => $item->item->alert_quantity,
                            ]);
                            $item->item->stockTrails()->create([
                                'weight'       => $weight,
                                'quantity'     => $quantity,
                                'item_id'      => $item->item_id,
                                'unit_id'      => $item->unit_id,
                                'variation_id' => $variation->id,
                                'warehouse_id' => $transfer->to_warehouse_id,
                                'type'         => ($moveBack ? 'Editing' : 'Updating') . ' Transfer Item',
                            ])->referencesObject($item);
                        }
                    }
                }
            }
        }
    }

    private function updated(TransferEvent $event)
    {
        TransferNotifier::fire('updated', $event->transfer, __('Transfer Edited'), $event->transfer->reference . ' was edited: ' . TransferNotifier::route($event->transfer) . '.', auth()->id());

        if (! $event->original->draft) {
            $this->setStock($event->original, true);
        }
        if (! $event->transfer->draft) {
            $this->setStock($event->transfer);
        }

        if ($event->original->draft && ! $event->transfer->draft) {
            $this->notifyTransferDone($event->transfer);
        }
    }

    private function notifyTransferDone($transfer): void
    {
        $transfer->loadMissing(['fromWarehouse:id,name,status', 'toWarehouse:id,name,status', 'user:id,name', 'items.item:id,name,code', 'items.unit:id,name']);

        // Transfers workflow page (Who Gets Notified / Messages): "Transfer Completed", then "Transfer Dispatched" for the destination's engineers
        TransferNotifier::fire('done', $transfer, __('Transfer Completed'), $transfer->reference . ' completed: ' . optional($transfer->fromWarehouse)->name . ' → ' . optional($transfer->toWarehouse)->name . '.', auth()->id() ?: optional($transfer->user)->id, [
            'items' => $this->extractItems($transfer->items),
        ]);
        TransferNotifier::fire('dispatched', $transfer, __('Transfer Dispatched'), $transfer->reference . ' is on its way: ' . optional($transfer->fromWarehouse)->name . ' → ' . optional($transfer->toWarehouse)->name . '.', auth()->id() ?: optional($transfer->user)->id);

        if (
            MainWarehouseEmailService::isMainWarehouseCategory(optional($transfer->fromWarehouse)->status) ||
            MainWarehouseEmailService::isMainWarehouseCategory(optional($transfer->toWarehouse)->status)
        ) {
            $warehouseLabel = optional($transfer->fromWarehouse)->name . ' → ' . optional($transfer->toWarehouse)->name;

            $performedBy = optional(auth()->user())->name ?: optional($transfer->user)->name;

            MainWarehouseEmailService::report(
                'transfers', 'report', $transfer,
                'Transfer',
                (string) $transfer->reference,
                $warehouseLabel,
                [
                    'Date'           => $transfer->date,
                    'From Warehouse' => optional($transfer->fromWarehouse)->name,
                    'To Warehouse'   => optional($transfer->toWarehouse)->name,
                    'Done By'        => optional($transfer->user)->name,
                ],
                $transfer->account_id,
                $this->extractItems($transfer->items),
                $performedBy,
                warehouseIds: array_filter([$transfer->from_warehouse_id, $transfer->to_warehouse_id])
            );
        }
    }

    private function extractItems($items): array
    {
        return $items->map(fn ($row) => [
            'code'     => optional($row->item)->code ?? '—',
            'name'     => optional($row->item)->name ?? '—',
            'quantity' => $row->quantity,
            'unit'     => optional($row->unit)->name ?? '—',
        ])->values()->all();
    }
}
