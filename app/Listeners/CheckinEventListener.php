<?php

namespace App\Listeners;

use App\Events\CheckinEvent;
use App\Services\MainWarehouseEmailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class CheckinEventListener implements ShouldQueue
{
    use InteractsWithQueue;

    public $checkin;

    public $track_weight;

    public function failed(CheckinEvent $event, $exception)
    {
        Log::error('CheckinEvent failed!', ['Error' => $exception, 'Checkin' => $event->checkin]);
    }

    public function handle(CheckinEvent $event)
    {
        $this->track_weight = get_settings('track_weight');
        $this->{$event->method}($event);
    }

    private function created(CheckinEvent $event)
    {
        if (! $event->checkin->draft) {
            $this->setStock($event->checkin);
            $this->notifyMainWarehouseCheckinDone($event->checkin);
        }

        // after the stock work, so a notice can never get in its way
        $this->alert('created', $event->checkin, __('Check-in Created'), $event->checkin->reference . ' was created' . ($event->checkin->draft ? ' as a draft' : '') . $this->forWarehouse($event->checkin) . '.', ['skip_actor' => true]);
    }

    private function deleted(CheckinEvent $event)
    {
        if (! $event->checkin->draft) {
            $this->setStock($event->checkin, true);
        }

        // Check-ins workflow page (Who Gets Notified / Messages). "Deleted for good" is its own event; while it is switched off the older
        // "Check-in Deleted" notice is sent for a permanent delete too, exactly as before.
        if ($event->permanent && \App\Services\WorkflowGate::on('checkins', 'permanently_deleted', (int) ($event->checkin->account_id ?? 1))) {
            $this->alert('permanently_deleted', $event->checkin, __('Check-in Deleted for Good'), $event->checkin->reference . ' was deleted for good' . $this->byActor() . '.', ['url' => route('checkins.index'), 'skip_actor' => true]);

            return;
        }
        $this->alert('deleted', $event->checkin, __('Checkin Deleted'), $event->checkin->reference . ' was deleted.', ['url' => route('deleted.index', ['type' => 'checkins'])]);
    }

    private function restored(CheckinEvent $event)
    {
        if (! $event->checkin->draft) {
            $this->setStock($event->checkin);
        }

        $this->alert('restored', $event->checkin, __('Check-in Restored'), $event->checkin->reference . ' was restored from the deleted records' . $this->byActor() . '.', ['skip_actor' => true]);
    }

    private function byActor(): string
    {
        return auth()->user() ? ' by ' . auth()->user()->name : '';
    }

    private function forWarehouse($checkin): string
    {
        $checkin->loadMissing('warehouse:id,name,status');

        return $checkin->warehouse ? ' for ' . $checkin->warehouse->name : '';
    }

    private function setStock($checkin, $decrease = false)
    {
        foreach ($checkin->items as $item) {
            if ($item->item->track_quantity || $item->item->track_weight) {
                $stock = $item->stock()->main()->ofWarehouse($checkin->warehouse_id)->first();
                if ($stock) {
                    $weight = $stock->weight;
                    $quantity = $stock->quantity;
                    if ($item->item->track_quantity) {
                        $base_quantity = convert_to_base_quantity($item->quantity, $item->unit);
                        $quantity = $decrease ? $stock->quantity - $base_quantity : $stock->quantity + $base_quantity;
                    }
                    if ($this->track_weight && $item->item->track_weight) {
                        $weight = $decrease ? $stock->weight - $item->weight : $stock->weight + $item->weight;
                    }
                    if ($stock->quantity != $quantity || $stock->weight != $weight) {
                        $stock->update(['quantity' => $quantity, 'weight' => $weight]);
                        // event(new \App\Events\StockEvent($item, $quantity, $weight));
                        $item->item->stockTrails()->create([
                            'variation_id' => null,
                            'weight'       => $weight,
                            'quantity'     => $quantity,
                            'item_id'      => $item->item_id,
                            'unit_id'      => $item->unit_id,
                            'warehouse_id' => $checkin->warehouse_id,
                            'type'         => ($decrease ? 'Editing' : 'Updating') . ' Checkin Item',
                        ])->referencesObject($item);
                    }
                } else {
                    $weight = 0;
                    $quantity = 0;
                    if ($item->item->track_quantity) {
                        $base_quantity = convert_to_base_quantity($item->quantity, $item->unit);
                        $quantity = $decrease ? 0 - $base_quantity : $base_quantity;
                    }
                    if ($this->track_weight && $item->item->track_weight) {
                        $weight = $decrease ? 0 - $item->weight : $item->weight;
                    }
                    $item->item->stock()->create([
                        'variation_id'   => null,
                        'weight'         => $weight,
                        'quantity'       => $quantity,
                        'account_id'     => getAccountId(),
                        'warehouse_id'   => $checkin->warehouse_id,
                        'rack_location'  => $item->item->rack_location,
                        'alert_quantity' => $item->item->alert_quantity,
                    ]);
                    $item->item->stockTrails()->create([
                        'variation_id' => null,
                        'weight'       => $weight,
                        'quantity'     => $quantity,
                        'item_id'      => $item->item_id,
                        'unit_id'      => $item->unit_id,
                        'warehouse_id' => $checkin->warehouse_id,
                        'type'         => ($decrease ? 'Editing' : 'Updating') . ' Checkin Item',
                    ])->referencesObject($item);
                }
                if ($item->variations && $item->variations->isNotEmpty()) {
                    foreach ($item->variations as $variation) {
                        $variationStock = $variation->stock()->ofWarehouse($checkin->warehouse_id)->first();
                        if ($variationStock) {
                            $weight = $variationStock->weight;
                            $quantity = $variationStock->quantity;
                            if ($item->item->track_quantity) {
                                $base_quantity = convert_to_base_quantity($variation->pivot->quantity, $variation->unit);
                                $quantity = $decrease ? $variationStock->quantity - $base_quantity : $variationStock->quantity + $base_quantity;
                            }
                            if ($this->track_weight && $item->item->track_weight) {
                                $weight = $decrease ? $variationStock->weight - $variation->pivot->weight : $variationStock->weight + $variation->pivot->weight;
                            }
                            if ($variationStock->quantity != $quantity || $variationStock->weight != $weight) {
                                $variationStock->update(['quantity' => $quantity, 'weight' => $weight]);
                                // event(new \App\Events\StockEvent($item, $quantity, $weight, $variation->id));
                                $item->item->stockTrails()->create([
                                    'weight'       => $weight,
                                    'quantity'     => $quantity,
                                    'item_id'      => $item->item_id,
                                    'unit_id'      => $item->unit_id,
                                    'variation_id' => $variation->id,
                                    'warehouse_id' => $checkin->warehouse_id,
                                    'type'         => ($decrease ? 'Editing' : 'Updating') . ' Checkin Item Variant',
                                ])->referencesObject($item);
                            }
                        } else {
                            $weight = 0;
                            $quantity = 0;
                            if ($item->item->track_quantity) {
                                $base_quantity = convert_to_base_quantity($variation->pivot->quantity, $variation->unit);
                                $quantity = $decrease ? 0 - $base_quantity : $base_quantity;
                            }
                            if ($this->track_weight && $item->item->track_weight) {
                                $weight = $decrease ? 0 - $variation->pivot->weight : $variation->pivot->weight;
                            }
                            $variation->stock()->create([
                                'weight'         => $weight,
                                'quantity'       => $quantity,
                                'account_id'     => getAccountId(),
                                'item_id'        => $item->item_id,
                                'warehouse_id'   => $checkin->warehouse_id,
                                'rack_location'  => $item->item->rack_location,
                                'alert_quantity' => $item->item->alert_quantity,
                            ]);
                            $item->item->stockTrails()->create([
                                'weight'       => $weight,
                                'quantity'     => $quantity,
                                'item_id'      => $item->item_id,
                                'unit_id'      => $item->unit_id,
                                'variation_id' => $variation->id,
                                'warehouse_id' => $checkin->warehouse_id,
                                'type'         => ($decrease ? 'Editing' : 'Updating') . ' Checkin Item Variant',
                            ])->referencesObject($item);
                        }
                    }
                }
            }
        }
    }

    private function updated(CheckinEvent $event)
    {
        if (! $event->original->draft) {
            $this->setStock($event->original, true);
        }
        if (! $event->checkin->draft) {
            $this->setStock($event->checkin);
        }

        $this->alert('updated', $event->checkin, __('Check-in Edited'), $event->checkin->reference . ' was edited' . $this->forWarehouse($event->checkin) . $this->byActor() . '.', ['skip_actor' => true]);

        if ($event->original->draft && ! $event->checkin->draft) {
            $this->notifyMainWarehouseCheckinDone($event->checkin);
        }
    }

    private function notifyMainWarehouseCheckinDone($checkin): void
    {
        $checkin->loadMissing(['warehouse:id,name,status', 'contact:id,name', 'user:id,name', 'items.item:id,name,code', 'items.unit:id,name']);
        if (! MainWarehouseEmailService::isMainWarehouseCategory(optional($checkin->warehouse)->status)) {
            return;
        }

        $this->alert('main_done', $checkin, __('Main Warehouse Checkin Completed'), $checkin->reference . ' completed for ' . (optional($checkin->warehouse)->name ?? 'Main Warehouse') . '.', ['items' => $this->extractItems($checkin->items), 'details' => [__('Date') => $checkin->date, __('Contact') => optional($checkin->contact)->name, __('Done By') => optional($checkin->user)->name]]);

        $performedBy = optional(auth()->user())->name ?: optional($checkin->user)->name;

        MainWarehouseEmailService::report(
            'checkins', 'report', $checkin,
            'Checkin',
            (string) $checkin->reference,
            optional($checkin->warehouse)->name ?? 'Main Warehouse',
            [
                'Date'    => $checkin->date,
                'Contact' => optional($checkin->contact)->name,
                'Done By' => optional($checkin->user)->name,
            ],
            $checkin->account_id,
            $this->extractItems($checkin->items),
            $performedBy,
            warehouseIds: array_filter([$checkin->warehouse_id])
        );
    }

    /** Hands a check-in event to the Check-ins workflow page's settings. */
    private function alert(string $event, $checkin, string $title, string $message, array $ctx = []): void
    {
        $checkin->loadMissing(['warehouse:id,name,status', 'items', 'user:id,name']);
        \App\Services\Workflow\WorkflowMessenger::dispatch('checkins', $event, $checkin, auth()->user(), $ctx + [
            'account_id' => $checkin->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => route('checkins.show', $checkin->id),
            'owner_user_id' => (int) $checkin->user_id, 'warehouse_ids' => array_filter([$checkin->warehouse_id]),
            'values' => ['reference' => $checkin->reference, 'warehouse' => optional($checkin->warehouse)->name ?? '', 'itemscount' => (string) $checkin->items->count()],
        ]);
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
