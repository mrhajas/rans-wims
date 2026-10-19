<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Releasing or stopping a transfer that waits for approval. One place for the rules, used by the Transfers page (one transfer) and by Multi Transfer
 * (all the transfers of a request). Stock only moves on approval; a cancelled transfer never moved anything.
 */
class TransferApproval
{
    /** @return string|null the reason it could not be approved (nothing changed), or null when it was approved */
    public static function approve(Transfer $transfer, User $user): ?string
    {
        $error = null;
        DB::transaction(function () use ($transfer, $user, &$error) {
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->first();
            if (! $locked || $locked->approval_status !== Transfer::APPROVAL_PENDING) {
                $error = __('This transfer is not waiting for approval.');

                return;
            }
            // weeks may have passed since it was saved: the stock is checked again
            if (! get_settings('over_selling')) {
                // the same item on several lines is added together: each line alone may fit while all of them together do not
                $needed = [];
                $first = [];
                foreach ($locked->load(['items.item', 'items.unit'])->items as $line) {
                    if (! $line->item || ! $line->item->track_quantity || $line->variations()->exists()) {
                        continue;
                    }
                    $needed[$line->item_id] = ($needed[$line->item_id] ?? 0.0) + (float) convert_to_base_quantity($line->quantity, $line->unit);
                    $first[$line->item_id] ??= $line;
                }
                foreach ($needed as $itemId => $need) {
                    $line = $first[$itemId];
                    $stock = $line->stock()->main()->ofWarehouse($locked->from_warehouse_id)->first();
                    $available = $stock ? (float) $stock->quantity : 0.0;
                    if (round($need, 4) > 0 && round($available, 4) < round($need, 4)) {
                        $error = __(':item has only :available in stock, :needed are needed.', ['item' => $line->item->name, 'available' => $available + 0, 'needed' => round($need, 4) + 0]);

                        return;
                    }
                }
            }
            $locked->forceFill(['approval_status' => Transfer::APPROVAL_APPROVED, 'approved_by' => $user->id, 'approved_at' => now(), 'draft' => false])->save();
            $transfer->setRawAttributes($locked->getAttributes(), true);
        });
        if ($error) {
            return $error;
        }

        $transfer->load(['items.item', 'items.unit', 'items.variations']);
        event(new \App\Events\TransferEvent($transfer, 'approved'));
        log_activity('Transfer Approved', ['reference' => $transfer->reference, 'approved_by' => $user->id], $transfer, 'Transfer');
        TransferNotifier::fire('approved', $transfer, __('Transfer approved'), $transfer->reference . ' ' . __('was approved by :name.', ['name' => $user->name]), $user->id, ['skip_actor' => true]);
        MultiTransferNotifier::childChanged($transfer, $user);

        return null;
    }

    /** @return string|null the reason it could not be cancelled, or null when it was */
    public static function cancel(Transfer $transfer, User $user, ?string $reason = null): ?string
    {
        $error = null;
        DB::transaction(function () use ($transfer, $user, $reason, &$error) {
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->first();
            if (! $locked || $locked->approval_status !== Transfer::APPROVAL_PENDING) {
                $error = __('Only a transfer that is waiting for approval can be cancelled.');

                return;
            }
            $locked->forceFill(['approval_status' => Transfer::APPROVAL_CANCELLED, 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $transfer->setRawAttributes($locked->getAttributes(), true);
        });
        if ($error) {
            return $error;
        }
        log_activity('Transfer Cancelled', ['reference' => $transfer->reference, 'reason' => $reason], $transfer, 'Transfer');
        TransferNotifier::fire('cancelled', $transfer, __('Transfer cancelled'), $transfer->reference . ' ' . __('was cancelled by :name.', ['name' => $user->name]), $user->id, ['skip_actor' => true]);
        MultiTransferNotifier::childChanged($transfer, $user);

        return null;
    }
}
