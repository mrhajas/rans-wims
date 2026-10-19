<?php

namespace App\Services\Signatories\Documents\Support;

use App\Models\Checkin;
use App\Models\DeliveryNote;
use App\Models\GatePass;
use App\Models\Transfer;
use App\Services\Signatories\Documents\StockSigningGuard;

/**
 * What the Delivery Note and the Gate Pass really record, shared by both adapters (they are built the same way):
 *  - "prepared": created_by_user_id + created_at of the note or pass itself (the person who generated it, at the moment it was generated);
 *  - "approved": the note and the pass have no approval of their own. The only real approval behind them is the one on the PARENT TRANSFER
 *    (transfers.approved_by + approved_at), and it is reported only while that transfer is still approved. A check-in parent has no approval
 *    stage (its approved_by/approved_at columns are never written), a pending, cancelled or not-approval-required transfer has none either:
 *    the action is then absent and the "Approved By" line stays blank.
 */
final class PaperworkHistory
{
    /** The Transfer or Check-in the paper was generated from (not limited to the signed-in user's own records), or null. */
    public static function parent(DeliveryNote|GatePass $paper): ?object
    {
        return match ($paper->source_type) {
            'transfer' => Transfer::withTrashed()->withoutGlobalScope('mine')->find($paper->source_id),
            'checkin' => Checkin::withTrashed()->withoutGlobalScope('mine')->find($paper->source_id),
            default => null,
        };
    }

    public static function history(DeliveryNote|GatePass $paper): array
    {
        $h = [];
        $by = $paper->created_by_user_id ? (int) $paper->created_by_user_id : null;
        $at = StockSigningGuard::at($paper->created_at);
        if ($by || $at) {
            $h['prepared'] = [
                'user_id' => $by, 'at' => $at, 'name' => null,
                'quality' => $by && $at ? 'good' : 'partial',
                'note' => $by && $at ? null : 'Who generated this, or when, was not recorded.',
            ];
        }

        $parent = self::parent($paper);
        if ($parent instanceof Transfer && $parent->approval_status === Transfer::APPROVAL_APPROVED && $parent->approved_by && $parent->approved_at) {
            $h['approved'] = [
                'user_id' => (int) $parent->approved_by, 'at' => StockSigningGuard::at($parent->approved_at), 'name' => null,
                'quality' => 'good',
                'note' => null,
            ];
        }

        return $h;
    }
}
