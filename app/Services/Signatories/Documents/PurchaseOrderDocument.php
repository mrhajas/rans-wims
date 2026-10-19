<?php

namespace App\Services\Signatories\Documents;

use App\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity as ActivityLogRow;

/**
 * Purchase Order (Supplier Records).
 *
 * Real storage (SupplierRecords\PurchaseOrderController), one actor + one time per step:
 *  - created       user_id + created_at                                        (GOOD)
 *  - submitted     submitted_on (always stored) + submitted_by_user_id         (GOOD from the 2026-11-03 change on; an order submitted before it has the
 *                  time but no person: PARTIAL, never guessed)
 *  - approved      approved_by_user_id + approved_on                           (GOOD, stored since the module was built)
 *  - sent          sent_on (always stored) + sent_by_user_id                   (same as submitted: PARTIAL for orders sent before the change)
 *  - acknowledged  acknowledged_on + acknowledged_by_user_id: the person who RECORDED the supplier's acknowledgement (the supplier itself does not
 *                  sign in WIMS). Optional in the workflow: goods can be received straight after "Sent to Supplier", so it is reported only when the
 *                  stamp exists. Declared as an action but not part of the default setup.
 *
 * Status order: Draft < Submitted < Approved < Sent to Supplier < Acknowledged < Partially Delivered / Completed. A step is reported only while
 * the current status is at or past it. Rejected keeps its "submitted" step (it was rejected from Submitted) and nothing after. Cancelled can happen
 * from any open status and does not undo what happened before, so the steps that carry a stamp are reported. Receiving goods can move the status
 * between Sent / Partially Delivered / Completed (even back to Sent when a receipt is deleted) without touching any stamp.
 *
 * Edit after approval: only a Super Admin can edit a non-Draft order, and the edit keeps the approval fields. Since the content changed after the
 * approval, update() writes an activity-log row (EDITED_AFTER_APPROVAL) and when such a row is newer than approved_on the approval is reported with
 * quality 'partial' and a note, so the printed signature carries the warning.
 *
 * States: Draft = draft; Sent to Supplier and everything after it (Acknowledged, Partially Delivered, Completed) = final (the order was issued, the
 * last signing step is done); Submitted and Approved = in progress; Rejected and Cancelled = in progress (they never reach "issued").
 */
final class PurchaseOrderDocument implements SignableDocument
{
    /** The activity-log description PurchaseOrderController::update() writes when a non-Draft order that carries an approval is edited. */
    public const EDITED_AFTER_APPROVAL = 'Purchase Order edited after approval.';

    private const PAST_SUBMITTED = [PurchaseOrder::STATUS_SUBMITTED, PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_ACKNOWLEDGED, PurchaseOrder::STATUS_PARTIALLY_DELIVERED, PurchaseOrder::STATUS_COMPLETED, PurchaseOrder::STATUS_REJECTED];

    private const PAST_APPROVED = [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_ACKNOWLEDGED, PurchaseOrder::STATUS_PARTIALLY_DELIVERED, PurchaseOrder::STATUS_COMPLETED];

    private const PAST_SENT = [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_ACKNOWLEDGED, PurchaseOrder::STATUS_PARTIALLY_DELIVERED, PurchaseOrder::STATUS_COMPLETED];

    public static function key(): string
    {
        return 'purchase_order';
    }

    public static function label(): string
    {
        return 'Purchase Order';
    }

    public static function actions(): array
    {
        return [
            'created' => 'Prepared (order created)',
            'submitted' => 'Submitted for approval',
            'approved' => 'Approved',
            'sent' => 'Issued (sent to the supplier)',
            'acknowledged' => 'Supplier acknowledgement recorded',
        ];
    }

    public function find(int $id): ?object
    {
        return PurchaseOrder::query()->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->status) {
            PurchaseOrder::STATUS_DRAFT => 'draft',
            PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_ACKNOWLEDGED, PurchaseOrder::STATUS_PARTIALLY_DELIVERED, PurchaseOrder::STATUS_COMPLETED => 'final',
            default => 'in_progress',
        };
    }

    public function history(object $record): array
    {
        $status = (string) $record->status;
        $cancelled = $status === PurchaseOrder::STATUS_CANCELLED;
        $out = ['created' => $this->entry($record->user_id, $record->created_at, 'The person who created the order is not recorded.')];

        if (in_array($status, self::PAST_SUBMITTED, true) || ($cancelled && $record->submitted_on)) {
            $out['submitted'] = $this->entry($record->submitted_by_user_id, $record->submitted_on, $record->submitted_by_user_id ? 'The time of the submission is not recorded.' : 'Who submitted this order was not recorded (it was submitted before WIMS stored the person).');
        }

        if (in_array($status, self::PAST_APPROVED, true) || ($cancelled && $record->approved_on)) {
            $approved = $this->entry($record->approved_by_user_id, $record->approved_on, 'The approval is not fully recorded on this order.');
            if ($record->approved_on && $this->editedAfter($record, Carbon::parse($record->approved_on))) {
                $approved['quality'] = 'partial';
                $approved['note'] = 'This order was edited after it was approved.';
            }
            $out['approved'] = $approved;
        }

        if (in_array($status, self::PAST_SENT, true) || ($cancelled && $record->sent_on)) {
            $out['sent'] = $this->entry($record->sent_by_user_id, $record->sent_on, $record->sent_by_user_id ? 'The time the order was sent is not recorded.' : 'Who sent this order was not recorded (it was sent before WIMS stored the person).');
        }

        if ($record->acknowledged_on && (in_array($status, self::PAST_SENT, true) || $cancelled)) {
            $out['acknowledged'] = $this->entry($record->acknowledged_by_user_id, $record->acknowledged_on, 'Who recorded the supplier acknowledgement was not recorded.');
        }

        return $out;
    }

    private function editedAfter(object $record, Carbon $approvedAt): bool
    {
        if (! $record->getKey()) {
            return false;
        }

        return ActivityLogRow::query()->where('subject_type', $record->getMorphClass())->where('subject_id', $record->getKey())
            ->where('description', self::EDITED_AFTER_APPROVAL)->where('created_at', '>', $approvedAt)->exists();
    }

    private function entry($userId, $at, string $note): array
    {
        $at = StockSigningGuard::at($at);
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    /** A copy of the order as it will be once $userId sends it to the supplier (nothing is saved): for the finalisation guard. */
    public function asIfSent(object $record, int $userId): object
    {
        $copy = clone $record;
        $copy->forceFill(['status' => PurchaseOrder::STATUS_SENT, 'sent_by_user_id' => $userId, 'sent_on' => now()]);

        return $copy;
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'prepared_by', 'action' => 'created', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'submitted_by', 'action' => 'submitted', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
            ['role' => 'issued_by', 'action' => 'sent', 'slot' => 4, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
