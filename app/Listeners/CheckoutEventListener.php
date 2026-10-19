<?php

namespace App\Listeners;

use App\Models\Unit;
use App\Events\CheckoutEvent;
use App\Services\MainWarehouseEmailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class CheckoutEventListener implements ShouldQueue
{
    use InteractsWithQueue;

    public $checkout;

    public $track_weight;

    public function failed(CheckoutEvent $event, $exception)
    {
        Log::error('CheckoutEvent failed!', ['Error' => $exception, 'Checkout' => $event->checkout]);
    }

    public function handle(CheckoutEvent $event)
    {
        $this->track_weight = get_settings('track_weight');
        $this->{$event->method}($event);
    }

    private function created(CheckoutEvent $event)
    {
        if (! $event->checkout->draft) {
            $this->setStock($event->checkout);
            $this->notifyMainWarehouseCheckoutDone($event->checkout);
        }

        // after the stock work, so a notice can never get in its way
        $this->alert('created', $event->checkout, __('Check-out Created'), $event->checkout->reference . ' was created' . ($event->checkout->draft ? ' as a draft' : '') . $this->forWarehouse($event->checkout) . '.', ['skip_actor' => true]);
    }

    private function deleted(CheckoutEvent $event)
    {
        if (! $event->checkout->draft) {
            $this->setStock($event->checkout, true);
        }

        // Check-outs workflow page (Who Gets Notified / Messages). "Deleted for good" is its own event; while it is switched off the older
        // "Check-out Deleted" notice is sent for a permanent delete too, exactly as before.
        if ($event->permanent && \App\Services\WorkflowGate::on('checkouts', 'permanently_deleted', (int) ($event->checkout->account_id ?? 1))) {
            $this->alert('permanently_deleted', $event->checkout, __('Check-out Deleted for Good'), $event->checkout->reference . ' was deleted for good' . $this->byActor() . '.', ['url' => route('checkouts.index'), 'skip_actor' => true]);

            return;
        }
        $this->alert('deleted', $event->checkout, __('Checkout Deleted'), $event->checkout->reference . ' was deleted.', ['url' => route('deleted.index', ['type' => 'checkouts'])]);
    }

    private function restored(CheckoutEvent $event)
    {
        if (! $event->checkout->draft) {
            $this->setStock($event->checkout);
        }

        $this->alert('restored', $event->checkout, __('Check-out Restored'), $event->checkout->reference . ' was restored from the deleted records' . $this->byActor() . '.', ['skip_actor' => true]);
    }

    private function byActor(): string
    {
        return auth()->user() ? ' by ' . auth()->user()->name : '';
    }

    private function forWarehouse($checkout): string
    {
        $checkout->loadMissing('warehouse:id,name,status');

        return $checkout->warehouse ? ' for ' . $checkout->warehouse->name : '';
    }

    private function setStock($checkout, $increase = false)
    {
        foreach ($checkout->items as $item) {
            if ($item->item->track_quantity || $item->item->track_weight) {
                $stock = $item->stock()->main()->ofWarehouse($checkout->warehouse_id)->first();
                if ($stock) {
                    $weight = $stock->weight;
                    $quantity = $stock->quantity;
                    if ($item->item->track_quantity) {
                        $base_quantity = convert_to_base_quantity($item->quantity, $item->unit);
                        $quantity = $increase ? $stock->quantity + $base_quantity : $stock->quantity - $base_quantity;
                    }
                    if ($this->track_weight && $item->item->track_weight) {
                        $weight = $increase ? $stock->weight + $item->weight : $stock->weight - $item->weight;
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
                            'warehouse_id' => $checkout->warehouse_id,
                            'type'         => ($increase ? 'Editing' : 'Updating') . ' Checkout Item',
                        ])->referencesObject($item);
                    }
                } else {
                    $weight = 0;
                    $quantity = 0;
                    if ($item->item->track_quantity) {
                        $base_quantity = convert_to_base_quantity($item->quantity, $item->unit);
                        $quantity = $increase ? $base_quantity : 0 - $base_quantity;
                    }
                    if ($this->track_weight && $item->item->track_weight) {
                        $weight = $increase ? $item->weight : 0 - $item->weight;
                    }
                    $item->item->stock()->create([
                        'variation_id'   => null,
                        'weight'         => $weight,
                        'quantity'       => $quantity,
                        'account_id'     => getAccountId(),
                        'warehouse_id'   => $checkout->warehouse_id,
                        'rack_location'  => $item->item->rack_location,
                        'alert_quantity' => $item->item->alert_quantity,
                    ]);
                    $item->item->stockTrails()->create([
                        'variation_id' => null,
                        'weight'       => $weight,
                        'quantity'     => $quantity,
                        'item_id'      => $item->item_id,
                        'unit_id'      => $item->unit_id,
                        'warehouse_id' => $checkout->warehouse_id,
                        'type'         => ($increase ? 'Editing' : 'Updating') . ' Checkout Item',
                    ])->referencesObject($item);
                }
                if ($item->variations && $item->variations->isNotEmpty()) {
                    foreach ($item->variations as $variation) {
                        $variationStock = $variation->stock()->ofWarehouse($checkout->warehouse_id)->first();
                        if ($variationStock) {
                            $weight = $variationStock->weight;
                            $quantity = $variationStock->quantity;
                            if ($item->item->track_quantity) {
                                // $unit          = $variation->pivot->unit_id ? Unit::find($variation->pivot->unit_id) : null;
                                $base_quantity = convert_to_base_quantity($variation->pivot->quantity, $variation->unit);
                                $quantity = $increase ? $variationStock->quantity + $base_quantity : $variationStock->quantity - $base_quantity;
                            }
                            if ($this->track_weight && $item->item->track_weight) {
                                $weight = $increase ? $variationStock->weight + $variation->pivot->weight : $variationStock->weight - $variation->pivot->weight;
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
                                    'warehouse_id' => $checkout->warehouse_id,
                                    'type'         => ($increase ? 'Editing' : 'Updating') . ' Checkout Item Variant',
                                ])->referencesObject($item);
                            }
                        } else {
                            $weight = 0;
                            $quantity = 0;
                            if ($item->item->track_quantity) {
                                // $unit          = $variation->pivot->unit_id ? Unit::find($variation->pivot->unit_id) : null;
                                $base_quantity = convert_to_base_quantity($variation->pivot->quantity, $variation->unit);
                                $quantity = $increase ? $base_quantity : 0 - $base_quantity;
                            }
                            if ($this->track_weight && $item->item->track_weight) {
                                $weight = $increase ? $variation->pivot->weight : 0 - $variation->pivot->weight;
                            }
                            $variation->stock()->create([
                                'weight'         => $weight,
                                'quantity'       => $quantity,
                                'account_id'     => getAccountId(),
                                'item_id'        => $item->item_id,
                                'warehouse_id'   => $checkout->warehouse_id,
                                'rack_location'  => $item->item->rack_location,
                                'alert_quantity' => $item->item->alert_quantity,
                            ]);
                            $item->item->stockTrails()->create([
                                'weight'       => $weight,
                                'quantity'     => $quantity,
                                'item_id'      => $item->item_id,
                                'unit_id'      => $item->unit_id,
                                'variation_id' => $variation->id,
                                'warehouse_id' => $checkout->warehouse_id,
                                'type'         => ($increase ? 'Editing' : 'Updating') . ' Checkout Item Variant',
                            ])->referencesObject($item);
                        }
                    }
                }
            }
        }
    }

    private function updated(CheckoutEvent $event)
    {
        if (! $event->original->draft) {
            $this->setStock($event->original, true);
        }
        if (! $event->checkout->draft) {
            $this->setStock($event->checkout);
        }

        $this->alert('updated', $event->checkout, __('Check-out Edited'), $event->checkout->reference . ' was edited' . $this->forWarehouse($event->checkout) . $this->byActor() . '.', ['skip_actor' => true]);

        if ($event->original->draft && ! $event->checkout->draft) {
            $this->notifyMainWarehouseCheckoutDone($event->checkout);
        }
    }

    private function notifyMainWarehouseCheckoutDone($checkout): void
    {
        $checkout->loadMissing(['warehouse:id,name,status', 'contact:id,name', 'user:id,name', 'items.item:id,name,code', 'items.unit:id,name']);
        if (! MainWarehouseEmailService::isMainWarehouseCategory(optional($checkout->warehouse)->status)) {
            return;
        }

        $this->alert('main_done', $checkout, __('Main Warehouse Checkout Completed'), $checkout->reference . ' completed for ' . (optional($checkout->warehouse)->name ?? 'Main Warehouse') . '.', ['items' => $this->extractItems($checkout->items), 'details' => [__('Date') => $checkout->date, __('Contact') => optional($checkout->contact)->name, __('Done By') => optional($checkout->user)->name]]);
        // a direct check-out from a Main Warehouse is also flagged as a process anomaly (material should normally leave by Transfer)
        $this->alert('main_anomaly', $checkout, __('Checkout at Main Warehouse (anomaly)'), $checkout->reference . ' was checked out directly from ' . (optional($checkout->warehouse)->name ?? 'a Main Warehouse') . ' by ' . (optional($checkout->user)->name ?? '-') . '. Material normally leaves a Main Warehouse via Transfer only.');

        $performedBy = optional(auth()->user())->name ?: optional($checkout->user)->name;

        MainWarehouseEmailService::report(
            'checkouts', 'report', $checkout,
            'Checkout',
            (string) $checkout->reference,
            optional($checkout->warehouse)->name ?? 'Main Warehouse',
            [
                'Date'    => $checkout->date,
                'Contact' => optional($checkout->contact)->name,
                'Done By' => optional($checkout->user)->name,
            ],
            $checkout->account_id,
            $this->extractItems($checkout->items),
            $performedBy,
            warehouseIds: array_filter([$checkout->warehouse_id])
        );
    }

    /** Hands a check-out event to the Check-outs workflow page's settings. */
    private function alert(string $event, $checkout, string $title, string $message, array $ctx = []): void
    {
        $checkout->loadMissing(['warehouse:id,name,status', 'user:id,name']);
        \App\Services\Workflow\WorkflowMessenger::dispatch('checkouts', $event, $checkout, auth()->user(), $ctx + [
            'account_id' => $checkout->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => route('checkouts.show', $checkout->id),
            'owner_user_id' => (int) $checkout->user_id, 'warehouse_ids' => array_filter([$checkout->warehouse_id]),
            'values' => ['reference' => $checkout->reference, 'warehouse' => optional($checkout->warehouse)->name ?? '', 'creator' => optional($checkout->user)->name ?? ''],
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
