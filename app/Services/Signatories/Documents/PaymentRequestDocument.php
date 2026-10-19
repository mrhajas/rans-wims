<?php

namespace App\Services\Signatories\Documents;

use App\Models\PaymentRequest;

/**
 * Payment Request (Supplier Records).
 *
 * Real records:
 *  - requested   requested_by_user_id + created_at: the person who raised it and the moment it was entered (the printed "Date requested" is typed). GOOD.
 *  - submitted   submitted_by_user_id + submitted_at, stamped since migration 2026_11_03_100000 (store() submits straight to "Pending Approval"). Older requests
 *                have no stamp, the action is absent for them (never back-filled).
 *  - approved    approved_by_user_id + approved_at, stamped by approve() since the same migration; reported only while the request still stands approved
 *                (Approved, Paid). Rejecting clears the pair. Requests approved before the migration have no stamp: the action is absent.
 *  - rejected    rejected_by_user_id + rejected_at, only while the status is Rejected.
 *
 * Not recorded as an action: "Paid". The money paid is a separate Payment record (with its own payment voucher and signatures); a request can be paid in
 * several parts, so there is no single payer to put on the request.
 *
 * States: no draft exists (a request is submitted when saved). "final" = Approved or Paid (approval is the last signing step; recording the payment adds
 * no signature to the request). Pending Approval and Rejected are "in_progress".
 */
final class PaymentRequestDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'payment_request';
    }

    public static function label(): string
    {
        return 'Payment request';
    }

    public static function actions(): array
    {
        return [
            'requested' => 'Requested (the person who raised the request)',
            'submitted' => 'Submitted for approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];
    }

    public function find(int $id): ?object
    {
        return PaymentRequest::query()->find($id);
    }

    public function state(object $record): string
    {
        return in_array($record->status, [PaymentRequest::STATUS_APPROVED, PaymentRequest::STATUS_PAID], true) ? 'final' : 'in_progress';
    }

    public function history(object $record): array
    {
        $h = [];
        $status = $record->status;

        if ($record->requested_by_user_id || $record->created_at) {
            $h['requested'] = $this->entry($record->requested_by_user_id, $record->created_at, 'Who raised this request, or when, was not recorded.');
        }
        if ($record->submitted_by_user_id || $record->submitted_at) {
            $h['submitted'] = $this->entry($record->submitted_by_user_id, $record->submitted_at, 'The submission is not fully recorded on this request.');
        }
        if (in_array($status, [PaymentRequest::STATUS_APPROVED, PaymentRequest::STATUS_PAID], true) && ($record->approved_by_user_id || $record->approved_at)) {
            $h['approved'] = $this->entry($record->approved_by_user_id, $record->approved_at, 'The approval is not fully recorded on this request.');
        }
        if ($status === PaymentRequest::STATUS_REJECTED && ($record->rejected_by_user_id || $record->rejected_at)) {
            $h['rejected'] = $this->entry($record->rejected_by_user_id, $record->rejected_at, 'The rejection is not fully recorded on this request.');
        }

        return $h;
    }

    private function entry($userId, $at, string $note): array
    {
        $at = StockSigningGuard::at($at);
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'requested_by', 'action' => 'requested', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }

    /** A copy of the request as it will be once $userId approves it (nothing is saved): for the finalisation guard. */
    public function asIfApproved(object $record, int $userId): object
    {
        $copy = clone $record;
        $copy->forceFill(['status' => PaymentRequest::STATUS_APPROVED, 'approved_by_user_id' => $userId, 'approved_at' => now(), 'rejected_by_user_id' => null, 'rejected_at' => null]);

        return $copy;
    }
}
