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
    /**
     * @param bool $removeDraft the approver agreed to take a plain draft out of draft and approve it in one step (a draft that leaves a MAIN warehouse)
     * @return string|null the reason it could not be approved (nothing changed), or null when it was approved
     */
    public static function approve(Transfer $transfer, User $user, bool $removeDraft = false, ?string $remark = null): ?string
    {
        $error = null;
        $remark = $remark !== null && trim($remark) !== '' ? mb_substr(trim($remark), 0, 300) : null;
        DB::transaction(function () use ($transfer, $user, $removeDraft, $remark, &$error) {
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->first();
            if ($locked && $locked->draft && $locked->approval_status !== Transfer::APPROVAL_PENDING && $locked->approval_status !== Transfer::APPROVAL_CANCELLED) {
                // a plain draft: it can only be approved once the approver has agreed to remove the draft status, and only when it leaves a main warehouse
                if (! $removeDraft) {
                    $error = __('This transfer is still a draft. Remove the draft status first, then approve it.');

                    return;
                }
                if (! Transfer::warehouseNeedsApproval($locked->from_warehouse_id)) {
                    $error = __('This draft leaves a warehouse that needs no approval. Open it and save it as final instead.');

                    return;
                }
                if ($problems = self::problems($locked)) {
                    $error = __('This draft cannot leave draft yet. Fix these first:') . ' ' . collect($problems)->map(fn ($p) => ($p['row'] ? __('Item') . ' ' . $p['row'] . ': ' : '') . $p['message'])->implode(' | ');

                    return;
                }
                $locked->forceFill(['approval_status' => Transfer::APPROVAL_PENDING])->save();
            }
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
                        $error = title_words(__(':item has only :available in stock, :needed are needed.', ['item' => item_text($line->item->name, $line->item->code), 'available' => $available + 0, 'needed' => round($need, 4) + 0]));

                        return;
                    }
                }
            }
            $locked->forceFill(['approval_status' => Transfer::APPROVAL_APPROVED, 'approved_by' => $user->id, 'approved_at' => now(), 'draft' => false] + (self::remarkColumnReady() ? ['approval_remark' => $remark] : []))->save();
            $transfer->setRawAttributes($locked->getAttributes(), true);
        });
        if ($error) {
            return $error;
        }

        $transfer->load(['items.item', 'items.unit', 'items.variations']);
        event(new \App\Events\TransferEvent($transfer, 'approved'));
        log_activity($removeDraft ? 'Transfer Approved (draft status removed)' : 'Transfer Approved', ['reference' => $transfer->reference, 'approved_by' => $user->id, 'remark' => $remark], $transfer, 'Transfer');
        TransferNotifier::fire('approved', $transfer, __('Transfer approved'), $transfer->reference . ' ' . __('was approved by :name.', ['name' => $user->name]) . ($remark ? ' ' . __('Remark:') . ' ' . $remark : ''), $user->id, ['skip_actor' => true]);
        MultiTransferNotifier::childChanged($transfer, $user);

        return null;
    }

    /** The remark column arrives with a migration; until it has run (the code is copied before it does on a deploy) approving works without storing the remark. */
    private static function remarkColumnReady(): bool
    {
        static $ready = null;
        try {
            return $ready ??= \Illuminate\Support\Facades\Schema::hasColumn('transfers', 'approval_remark');
        } catch (\Throwable $e) {
            return $ready = false;
        }
    }

    /** The ready-made remarks an approver can pick (type to search, or write their own). Soft-coded: one remark per line in the setting `transfer_approval_remarks`. */
    public static function remarkOptions(): array
    {
        $lines = preg_split('/\R/u', (string) get_settings('transfer_approval_remarks')) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));

        return $lines ?: [
            __('Go Ahead'),
            __('Confirm with Management'),
            __('Urgent, Dispatch Today'),
            __('Verify Stock Before Loading'),
            __('Coordinate with the Site Engineer'),
        ];
    }

    /**
     * What stops a saved DRAFT transfer from leaving draft, line by line (a draft is stored without any checking). Empty when it can be finalised.
     * Each problem names the row (1-based, as the form numbers them), the item and what to change. Uses the same stock rules as a final save.
     *
     * @return array<int, array{row: int|null, item: string|null, message: string}>
     */
    public static function problems(Transfer $transfer): array
    {
        $out = [];
        if (! Transfer::warehouseNeedsApproval($transfer->from_warehouse_id)) {
            return [['row' => null, 'item' => null, 'message' => __('This draft leaves a warehouse that needs no approval. Open it and save it as final instead.')]];
        }
        $transfer->load(['items.item', 'items.unit', 'items.variations']);
        $lines = $transfer->items->reject(fn ($l) => method_exists($l, 'trashed') && $l->trashed())->values();
        if ($lines->isEmpty()) {
            return [['row' => null, 'item' => null, 'message' => __('Add at least one item to the list.')]];
        }
        if ((int) $transfer->from_warehouse_id === (int) $transfer->to_warehouse_id) {
            $out[] = ['row' => null, 'item' => null, 'message' => __('Please select different warehouses.')];
        }
        $payload = [];
        foreach ($lines as $i => $line) {
            $name = optional($line->item)->name;
            $label = item_text($name, optional($line->item)->code);
            if (! $line->item) {
                $out[] = ['row' => $i + 1, 'item' => null, 'message' => __('This item no longer exists. Remove the row or choose another item.')];
            } elseif ($line->variations->isEmpty() && (float) $line->quantity <= 0) {
                $out[] = ['row' => $i + 1, 'item' => $name, 'message' => title_words(__('Enter a quantity greater than 0 for :item.', ['item' => $label]))];
            } elseif ($line->variations->contains(fn ($v) => (float) $v->pivot->quantity <= 0)) {
                $out[] = ['row' => $i + 1, 'item' => $name, 'message' => title_words(__('Every variation of :item needs a quantity greater than 0.', ['item' => $label]))];
            }
            if (! $line->item) {
                continue;
            }
            $payload[$i] = [
                'name' => $name, 'item_id' => $line->item_id, 'quantity' => (float) $line->quantity, 'unit_id' => $line->unit_id,
                'selected' => ['variations' => $line->variations->map(fn ($v) => ['variation_id' => $v->id, 'quantity' => (float) $v->pivot->quantity, 'unit_id' => $v->pivot->unit_id])->all()],
            ];
        }
        if (! get_settings('over_selling')) {
            foreach ((new \App\Helpers\CheckOverSelling())->check($payload, $transfer->from_warehouse_id) as $key => $message) {
                $row = (int) explode('.', $key)[1];
                $out[] = ['row' => $row + 1, 'item' => $payload[$row]['name'] ?? null, 'message' => $message];
            }
        }
        usort($out, fn ($a, $b) => ($a['row'] ?? 0) <=> ($b['row'] ?? 0));

        return $out;
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
