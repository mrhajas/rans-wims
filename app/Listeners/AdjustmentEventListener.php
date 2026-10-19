<?php

namespace App\Listeners;

use App\Events\AdjustmentEvent;
use App\Notifications\AppNotification;
use App\Services\MainWarehouseEmailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class AdjustmentEventListener implements ShouldQueue
{
    use InteractsWithQueue;

    public $adjustment;

    public $track_weight;

    public function failed(AdjustmentEvent $event, $exception)
    {
        Log::error('AdjustmentEvent failed!', ['Error' => $exception, 'Adjustment' => $event->adjustment]);
    }

    public function handle(AdjustmentEvent $event)
    {
        $this->track_weight = get_settings('track_weight');
        $this->{$event->method}($event);
    }

    private function created(AdjustmentEvent $event)
    {
        // Every ordinary Adjustment starts as draft=true (see
        // AdjustmentController::store()), so "created" and "needs approval"
        // used to always coincide. Super Adjustment's approve() is the one
        // caller that creates an Adjustment already draft=false — without
        // this guard, approving a Super Adjustment sent admins a "Pending
        // Approval" notification for an Adjustment that had already been
        // approved and had already mutated stock, misattributed as
        // "submitted by" whoever clicked approve.
        if ($event->adjustment->draft) {
            $this->notifyAdjustmentCreatedForApproval($event->adjustment);
        }

        if (! $event->adjustment->draft) {
            $this->setStock($event->adjustment, $event->adjustment->type != 'Addition');
            $this->notifyAdjustmentDone($event->adjustment);
        }

        $this->notifyAdjustmentActivity($event->adjustment, $event->adjustment->draft ? 'Created (Draft)' : 'Created');

        // after the stock work, so a notice can never get in its way
        $this->alert('created', $event->adjustment, __('Adjustment Created'), $event->adjustment->reference . ' was created' . ($event->adjustment->draft ? ' and waits for approval' : '') . $this->forWarehouse($event->adjustment) . '.', ['skip_actor' => true]);
    }

    private function deleted(AdjustmentEvent $event)
    {
        if (! $event->adjustment->draft) {
            $this->setStock($event->adjustment, $event->adjustment->type == 'Addition');
        }

        // "Deleted for good" is its own event; while it is switched off the older "Adjustment Deleted" notice is sent for a permanent delete too, as before.
        if ($event->permanent && \App\Services\WorkflowGate::on('adjustments', 'permanently_deleted', (int) ($event->adjustment->account_id ?? 1))) {
            $this->alert('permanently_deleted', $event->adjustment, __('Adjustment Deleted for Good'), $event->adjustment->reference . ' was deleted for good' . $this->byActor() . '.', ['url' => route('adjustments.index'), 'skip_actor' => true]);
        } else {
            $this->alert('deleted', $event->adjustment, __('Adjustment Deleted'), $event->adjustment->reference . ' was deleted.', ['url' => route('deleted.index', ['type' => 'adjustments'])]);
        }

        $this->notifyAdjustmentActivity($event->adjustment, 'Deleted');
    }

    private function restored(AdjustmentEvent $event)
    {
        if (! $event->adjustment->draft) {
            $this->setStock($event->adjustment, $event->adjustment->type != 'Addition');
        }

        $this->alert('restored', $event->adjustment, __('Adjustment Restored'), $event->adjustment->reference . ' was restored from the deleted records' . $this->byActor() . '.', ['skip_actor' => true]);
        $this->notifyAdjustmentActivity($event->adjustment, 'Restored');
    }

    private function byActor(): string
    {
        return auth()->user() ? ' by ' . auth()->user()->name : '';
    }

    private function forWarehouse($adjustment): string
    {
        $adjustment->loadMissing('warehouse:id,name,status');

        return $adjustment->warehouse ? ' for ' . $adjustment->warehouse->name : '';
    }

    private function setStock($adjustment, $decrease = false)
    {
        foreach ($adjustment->items as $item) {
            if ($item->item->track_quantity || $item->item->track_weight) {
                $stock = $item->stock()->main()->ofWarehouse($adjustment->warehouse_id)->first();
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
                            'warehouse_id' => $adjustment->warehouse_id,
                            'type'         => ($decrease ? 'Editing' : 'Updating') . ' Adjustment Item',
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
                        'warehouse_id'   => $adjustment->warehouse_id,
                        'rack_location'  => $item->item->rack_location,
                        'alert_quantity' => $item->item->alert_quantity,
                    ]);
                    $item->item->stockTrails()->create([
                        'variation_id' => null,
                        'weight'       => $weight,
                        'quantity'     => $quantity,
                        'item_id'      => $item->item_id,
                        'unit_id'      => $item->unit_id,
                        'warehouse_id' => $adjustment->warehouse_id,
                        'type'         => ($decrease ? 'Editing' : 'Updating') . ' Adjustment Item',
                    ])->referencesObject($item);
                }
                if ($item->variations && $item->variations->isNotEmpty()) {
                    foreach ($item->variations as $variation) {
                        $variationStock = $variation->stock()->ofWarehouse($adjustment->warehouse_id)->first();
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
                                    'warehouse_id' => $adjustment->warehouse_id,
                                    'type'         => ($decrease ? 'Editing' : 'Updating') . ' Adjustment Item',
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
                                'warehouse_id'   => $adjustment->warehouse_id,
                                'rack_location'  => $item->item->rack_location,
                                'alert_quantity' => $item->item->alert_quantity,
                            ]);
                            $item->item->stockTrails()->create([
                                'weight'       => $weight,
                                'quantity'     => $quantity,
                                'item_id'      => $item->item_id,
                                'unit_id'      => $item->unit_id,
                                'variation_id' => $variation->id,
                                'warehouse_id' => $adjustment->warehouse_id,
                                'type'         => ($decrease ? 'Editing' : 'Updating') . ' Adjustment Item',
                            ])->referencesObject($item);
                        }
                    }
                }
            }
        }
    }

    private function updated(AdjustmentEvent $event)
    {
        if (! $event->original->draft) {
            $this->setStock($event->original, $event->original->type == 'Addition');
        }
        if (! $event->adjustment->draft) {
            $this->setStock($event->adjustment, $event->adjustment->type != 'Addition');
        }

        $wasApproved = $event->original->draft && ! $event->adjustment->draft;

        if ($wasApproved) {
            $this->notifyAdjustmentDone($event->adjustment);
            $this->notifyAdjustmentApprovedToCreator($event->adjustment);
        }

        // an ordinary edit, or a Super Admin moving an approved / rejected record back to Pending (approving and rejecting have their own events)
        if (! $wasApproved) {
            $before = $event->original->status;
            $after = $event->adjustment->status;
            if ($before === $after) {
                $this->alert('updated', $event->adjustment, __('Adjustment Edited'), $event->adjustment->reference . ' was edited' . $this->forWarehouse($event->adjustment) . $this->byActor() . '.', ['skip_actor' => true]);
            } elseif ($after === \App\Models\Adjustment::STATUS_PENDING) {
                $this->alert('set_pending', $event->adjustment, __('Adjustment Set Back to Pending'), $event->adjustment->reference . ' was moved from ' . $before . ' back to Pending' . $this->byActor() . '. Any stock it had changed was put back.', ['skip_actor' => true]);
            }
        }

        $this->notifyAdjustmentActivity($event->adjustment, $wasApproved ? 'Approved' : 'Updated');
    }

    private function rejected(AdjustmentEvent $event)
    {
        $this->notifyAdjustmentRejectedToCreator($event->adjustment);
        $this->notifyAdjustmentActivity($event->adjustment, 'Rejected');
    }

    private function notifyAdjustmentCreatedForApproval($adjustment): void
    {
        $adjustment->loadMissing(['warehouse:id,name,status', 'user:id,name']);
        $this->alert('pending_approval', $adjustment, __('Adjustment Pending Approval'), $adjustment->reference . ' was submitted by ' . optional($adjustment->user)->name . ' for ' . optional($adjustment->warehouse)->name . '.');
    }

    private function notifyAdjustmentApprovedToCreator($adjustment): void
    {
        $adjustment->loadMissing(['user:id,name', 'approver:id,name']);
        $this->alert('approved', $adjustment, __('Adjustment Approved'), $adjustment->reference . ' has been approved by ' . (optional($adjustment->approver)->name ?: 'Admin') . ($adjustment->approved_by_role ? ' (' . $adjustment->approved_by_role . ')' : '') . '.', ['skip_actor' => true]);
    }

    private function notifyAdjustmentRejectedToCreator($adjustment): void
    {
        $adjustment->loadMissing(['user:id,name', 'rejecter:id,name']);
        $this->alert('rejected', $adjustment, __('Adjustment Rejected'), $adjustment->reference . ' was rejected by ' . (optional($adjustment->rejecter)->name ?: 'Admin') . '. Reason: ' . $adjustment->rejection_reason, ['remarks' => $adjustment->rejection_reason, 'skip_actor' => true]);
    }

    private function notifyAdjustmentDone($adjustment): void
    {
        $adjustment->loadMissing('warehouse:id,name,status');
        $this->alert('done', $adjustment, __('Adjustment Completed'), $adjustment->reference . ' completed for ' . optional($adjustment->warehouse)->name . '.');
    }

    /** Hands an adjustment event to the Adjustments workflow page's settings (Who Gets Notified / Messages). */
    private function alert(string $event, $adjustment, string $title, string $message, array $ctx = []): void
    {
        $adjustment->loadMissing(['warehouse:id,name,status', 'user:id,name']);
        \App\Services\Workflow\WorkflowMessenger::dispatch('adjustments', $event, $adjustment, auth()->user(), $ctx + [
            'account_id' => $adjustment->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => route('adjustments.show', $adjustment->id),
            'owner_user_id' => (int) $adjustment->user_id, 'warehouse_ids' => array_filter([$adjustment->warehouse_id]),
            'values' => ['reference' => $adjustment->reference, 'warehouse' => optional($adjustment->warehouse)->name ?? '', 'type' => (string) $adjustment->type, 'creator' => optional($adjustment->user)->name ?? ''],
        ]);
    }

    /**
     * Unlike Checkin/Checkout/Transfer, Adjustment emails are not restricted to
     * Main Warehouse category warehouses or to finalized (non-draft) records —
     * every create, update, delete, and restore is reported here.
     */
    private function notifyAdjustmentActivity($adjustment, string $action): void
    {
        $adjustment->loadMissing(['warehouse:id,name,status', 'user:id,name', 'items.item:id,name,code', 'items.unit:id,name']);

        $performedBy = optional(auth()->user())->name ?: optional($adjustment->user)->name;

        MainWarehouseEmailService::report(
            'adjustments', 'report', $adjustment,
            'Adjustment',
            (string) $adjustment->reference,
            optional($adjustment->warehouse)->name ?? 'N/A',
            [
                'Date'    => $adjustment->date,
                'Type'    => $adjustment->type,
                'Action'  => $action,
                'Status'  => $adjustment->rejected_at ? 'Rejected' : ($adjustment->draft ? 'Draft' : 'Finalized'),
                'Done By' => optional($adjustment->user)->name,
            ],
            $adjustment->account_id,
            $this->extractItems($adjustment->items),
            $performedBy,
            "Adjustment {$action} - {$adjustment->reference}",
            warehouseIds: array_filter([$adjustment->warehouse_id])
        );

        $this->alert('activity', $adjustment, __('Adjustment :action', ['action' => $action]), $adjustment->reference . ' ' . strtolower($action) . ' for ' . (optional($adjustment->warehouse)->name ?? 'N/A') . ' by ' . $performedBy . '.', ['items' => $this->extractItems($adjustment->items)]);
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
