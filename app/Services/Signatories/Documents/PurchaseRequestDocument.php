<?php

namespace App\Services\Signatories\Documents;

use App\Models\PurchaseRequest;

/**
 * Purchase Request (Supplier Records).
 *
 * Real records:
 *  - requested   user_id + created_at: the person who raised it and the moment it was entered. GOOD.
 *  - submitted   submitted_by_user_id + submitted_at, stamped since the migration 2026_11_03_100000 (a request is submitted the moment it is saved: store() creates it
 *                straight in "Pending Approval"). Requests made before that migration have no stamp, so the action is absent for them (never back-filled).
 *  - approved    approved_by_user_id + approved_at, stamped by the approve step since the same migration. Reported only while the request still stands approved
 *                (Approved, Converted to PO). A rejection clears the pair; a status that moved backwards never shows an old approval. Requests approved before the
 *                migration have no stamp: the action is absent, the "Approved By" line stays blank (and is flagged when the setup requires it).
 *  - rejected    rejected_by_user_id + rejected_at, only while the status is Rejected. Not mapped by default (there is no "Rejected By" printed line).
 *
 * States: "draft" = status Draft (never created by the current screens, kept for older data). "final" = Approved or Converted to PO: approval is the last
 * signing step. Pending Approval and Rejected are "in_progress" (a rejected request was not issued, nothing more is signed on it).
 */
final class PurchaseRequestDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'purchase_request';
    }

    public static function label(): string
    {
        return 'Purchase request';
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
        return PurchaseRequest::query()->find($id);
    }

    public function state(object $record): string
    {
        if ($record->status === PurchaseRequest::STATUS_DRAFT) {
            return 'draft';
        }

        return in_array($record->status, [PurchaseRequest::STATUS_APPROVED, PurchaseRequest::STATUS_CONVERTED], true) ? 'final' : 'in_progress';
    }

    public function history(object $record): array
    {
        $h = [];
        $status = $record->status;

        if ($record->user_id || $record->created_at) {
            $h['requested'] = $this->entry($record->user_id, $record->created_at, 'Who raised this request, or when, was not recorded.');
        }
        // submitted: the stamp only counts once the request left Draft
        if ($status !== PurchaseRequest::STATUS_DRAFT && ($record->submitted_by_user_id || $record->submitted_at)) {
            $h['submitted'] = $this->entry($record->submitted_by_user_id, $record->submitted_at, 'The submission is not fully recorded on this request.');
        }
        // approved: only while the approval still stands
        if (in_array($status, [PurchaseRequest::STATUS_APPROVED, PurchaseRequest::STATUS_CONVERTED], true) && ($record->approved_by_user_id || $record->approved_at)) {
            $h['approved'] = $this->entry($record->approved_by_user_id, $record->approved_at, 'The approval is not fully recorded on this request.');
        }
        if ($status === PurchaseRequest::STATUS_REJECTED && ($record->rejected_by_user_id || $record->rejected_at)) {
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
        $copy->forceFill(['status' => PurchaseRequest::STATUS_APPROVED, 'approved_by_user_id' => $userId, 'approved_at' => now(), 'rejected_by_user_id' => null, 'rejected_at' => null]);

        return $copy;
    }
}
