<?php

namespace App\Services\Signatories\Documents;

use App\Models\PaymentVoucher;
use Illuminate\Support\Carbon;

/**
 * Payment Voucher (single print, and the per-voucher block of the batch print).
 *
 * Real storage (PaymentVoucherController):
 *  - prepared   prepared_by_user_id + created_at                (GOOD, always stored on creation)
 *  - approved   approved_by_user_id + approved_at               (GOOD; written by approve() and bulkApprove*())
 *  - authorised authorised_by_user_id + authorised_at           (GOOD; written by authorise() and bulkApproveAndAuthorise())
 *  - paid       paid_by_user_id + paid_at                       (GOOD; written by recordPayment() on an Authorised voucher)
 * submit() stores no actor or time on the row, so there is no "submitted" action (the activity log is not used on purpose).
 *
 * Stale data guard: a voucher rejected at the authorisation stage keeps its approved_* columns (rejectAuthorisation() does not clear them,
 * and a resubmitted voucher keeps them until it is approved again). So an approval is reported only while the status is Approved or
 * Authorised; Draft, Submitted, Rejected and Cancelled never show it. Cancelled is left out on purpose: the row cannot tell whether the
 * approval it carries is current or left over from an earlier round.
 *
 * State: Draft = draft; Authorised (with or without payment) = final; everything else (Submitted, Approved, Rejected, Cancelled) = in progress.
 */
final class PaymentVoucherDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'payment_voucher';
    }

    public static function label(): string
    {
        return 'Payment Voucher';
    }

    public static function actions(): array
    {
        return ['prepared' => 'Prepared (voucher created)', 'approved' => 'Approved (Accountant)', 'authorised' => 'Authorised (Managing Director)', 'paid' => 'Paid'];
    }

    public function find(int $id): ?object
    {
        return PaymentVoucher::query()->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->status) {
            PaymentVoucher::STATUS_DRAFT => 'draft',
            PaymentVoucher::STATUS_AUTHORISED => 'final',
            default => 'in_progress',
        };
    }

    public function history(object $record): array
    {
        $out = [];
        $status = $record->status;

        // prepared: the creator is stored on the row and the row has its creation time
        $out['prepared'] = $this->entry($record->prepared_by_user_id, $record->created_at, 'The person who prepared the voucher is not recorded on it.');

        // approved: only while the voucher still stands at or past the Approved stage
        if (in_array($status, [PaymentVoucher::STATUS_APPROVED, PaymentVoucher::STATUS_AUTHORISED], true)) {
            $out['approved'] = $this->entry($record->approved_by_user_id, $record->approved_at, 'The approval is not fully recorded on this voucher.');
        }

        // authorised: only a voucher that stands as Authorised
        if ($status === PaymentVoucher::STATUS_AUTHORISED) {
            $out['authorised'] = $this->entry($record->authorised_by_user_id, $record->authorised_at, 'The authorisation is not fully recorded on this voucher.');
            // paid: the payment stamp, only meaningful on an Authorised voucher; nothing recorded = it was not paid
            if ($record->paid_by_user_id || $record->paid_at) {
                $out['paid'] = $this->entry($record->paid_by_user_id, $record->paid_at, 'The payment is not fully recorded on this voucher.');
            }
        }

        return $out;
    }

    /** One history row. Missing actor or time makes it 'partial' with a note instead of guessing. */
    private function entry($userId, $at, string $note): array
    {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : null);
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    public static function defaultMappings(): array
    {
        // Today's printed labels: Prepared By, Approved By (Accountant), Authorised By (Managing Director). The voucher print has no Paid By line, so "paid" (declared in actions) is left for the administrator to add as paid_by.
        return [
            ['role' => 'prepared_by', 'action' => 'prepared', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'authorized_by', 'action' => 'authorised', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->pv_number ?? ('#' . $record->id));
    }
}
