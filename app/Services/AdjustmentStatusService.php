<?php

namespace App\Services;

use App\Events\AdjustmentEvent;
use App\Models\Adjustment;
use App\Models\User;
use App\Support\ApproverRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an Adjustment between Pending / Approved / Rejected and keeps stock honest while doing it: leaving Approved reverses
 * the stock the record applied, entering Approved applies it. All of it goes through AdjustmentEvent('updated') with the
 * "before" copy, exactly like an ordinary edit, so the existing listener does the stock work and notifies the right people.
 */
class AdjustmentStatusService
{
    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException when the record is already in that status
     */
    public function change(Adjustment $adjustment, string $target, User $actor, ?string $reason = null, bool $force = false): Adjustment
    {
        // Signatories: refuse the final step when an administrator made a signature required and it is missing (inert until switched on).
        if ($target === Adjustment::STATUS_APPROVED && ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('adjustment', $adjustment, ['draft' => false, 'approved_by' => $actor->id, 'approved_at' => now(), 'rejected_by' => null, 'rejected_at' => null], __('approved')))) {
            throw ValidationException::withMessages(['status' => $refusal]);
        }

        $before = DB::transaction(function () use ($adjustment, $target, $actor, $reason, $force) {
            $locked = $adjustment->newQueryWithoutScopes()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status === $target, 422, __('This adjustment is already :status.', ['status' => strtolower($target)]));
            // Taking an approval back is only allowed shortly after it was given; later, balance the stock with a new Adjustment.
            if ($locked->approvalLocked() && $target !== Adjustment::STATUS_APPROVED && ! $force) {
                throw ValidationException::withMessages(['status' => Adjustment::reversalBlockedMessage()]);
            }

            $adjustment->load(['items.item', 'items.unit', 'items.variations']);
            $before = $adjustment->replicate();

            $fields = [
                'draft' => $target !== Adjustment::STATUS_APPROVED,
                'approved_by' => null, 'approved_by_role' => null, 'approved_at' => null,
                'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
            ];
            if ($target === Adjustment::STATUS_APPROVED) {
                $fields = array_merge($fields, ['approved_by' => $actor->id, 'approved_by_role' => ApproverRole::for($actor), 'approved_at' => now()]);
            } elseif ($target === Adjustment::STATUS_REJECTED) {
                $fields = array_merge($fields, ['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => trim((string) $reason)]);
            }

            $adjustment->forceFill($fields)->save();
            // The lines carry their own draft flag (stock reports read it), so they follow the header.
            $adjustment->items()->update(['draft' => $target !== Adjustment::STATUS_APPROVED]);

            return $before;
        });

        // The status moved, so any frozen signature set of the earlier status no longer applies.
        \App\Services\Signatories\Documents\StockSigningGuard::forget('adjustment', (int) $adjustment->id);

        $adjustment->unsetRelation('approver')->unsetRelation('rejecter');
        event(new AdjustmentEvent($adjustment, 'updated', $before));
        if ($target === Adjustment::STATUS_REJECTED) {
            $adjustment->load(['items.item', 'items.unit', 'warehouse', 'user', 'rejecter']);
            event(new AdjustmentEvent($adjustment, 'rejected'));
        }

        log_activity('Adjustment ' . ($target === Adjustment::STATUS_APPROVED ? 'Approved' : ($target === Adjustment::STATUS_REJECTED ? 'Rejected' : 'Set To Pending')), [
            'reference' => $adjustment->reference, 'warehouse_id' => $adjustment->warehouse_id, 'type' => $adjustment->type,
            'from' => $before->status, 'to' => $target, 'reason' => $reason,
        ], $adjustment, 'Adjustment');

        return $adjustment;
    }
}
