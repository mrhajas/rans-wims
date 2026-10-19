<?php

namespace App\Services\Signatories\Documents;

use App\Models\Payment;
use App\Models\PaymentRequest;
use Spatie\Activitylog\Models\Activity as ActivityLogRow;

/**
 * Supplier Payment Voucher (Supplier Records > Payments, the streamed PDF "payment voucher" of one recorded payment).
 *
 * Real storage (SupplierRecords\PaymentController):
 *  - paid      paid_by_user_id + created_at.  The person named "Payment Made By" on the voucher (the form preselects the person recording, who may
 *              pick another user). The time is created_at, the moment the payment was entered in WIMS: payments.paid_on is a plain DATE (no time)
 *              and is the date the money was paid, not a signing time. GOOD.
 *  - prepared  the person who really recorded the payment: the causer of the Payment's own "created" row in activity_log (Spatie model event,
 *              written by store() for the signed-in user), with that row's time. It is absent when no such row exists (logs pruned, record
 *              created by a script), never guessed from paid_by_user_id. Usually the same person as "paid", and different when somebody
 *              recorded the payment on behalf of the person who made it.
 *  - verified  verified_by_user_id + verified_on (a real timestamp), written once by verify() (Super Admin, one-shot, cannot be undone). GOOD.
 *
 * Deliberately NOT mapped: "approved". The approval lives on the Payment Request, and payment_requests stores NO approver and NO time
 * (columns: requested_by_user_id, status, approval_remarks; approve() only flips the status). Its activity_log rows are Spatie model events
 * with the causer and a time but NO changed attributes (properties hold only the browser context), so an "updated by X" row cannot be told
 * apart from an edit or from the automatic "Paid" flip made when the payment is recorded; the notification log depends on who the rules
 * address. Nothing genuine identifies the approver, so the action does not exist here. The voucher's printed "Approved By" stays a blank
 * wet-ink line, and "Received By (Supplier Signature)" is the supplier's own signature and is never touched.
 *
 * States: a payment exists only once recorded, so there is no draft. Pending Verification = in progress. Verified = final (verification is the
 * last workflow step and cannot be reversed).
 */
final class SupplierPaymentVoucherDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'supplier_payment_voucher';
    }

    public static function label(): string
    {
        return 'Supplier Payment Voucher';
    }

    public static function actions(): array
    {
        return ['prepared' => 'Prepared (payment recorded in WIMS)', 'approved' => 'Approved (payment request approved by)', 'paid' => 'Paid (payment made by)', 'verified' => 'Verified (director)'];
    }

    public function find(int $id): ?object
    {
        return Payment::query()->find($id);
    }

    public function state(object $record): string
    {
        return $record->verification_status === Payment::VERIFICATION_VERIFIED ? 'final' : 'in_progress';
    }

    public function history(object $record): array
    {
        $out = ['paid' => $this->entry($record->paid_by_user_id, $record->created_at, 'The person who made this payment is not recorded.')];

        // prepared: only when the audit log really holds who recorded the payment
        $created = ActivityLogRow::query()->where('subject_type', $record->getMorphClass())->where('subject_id', $record->getKey())->where('event', 'created')->whereNotNull('causer_id')->orderBy('id')->first();
        if ($created) {
            $out['prepared'] = $this->entry($created->causer_id, $created->created_at, 'The person who recorded this payment is not fully recorded.');
        }

        // approved: the approval of the PAYMENT REQUEST this payment settles (stamped since the Signatories release; older approvals have no stamp and show nothing)
        if ($record->payment_request_id && ($pr = PaymentRequest::query()->find($record->payment_request_id))
            && in_array($pr->status, [PaymentRequest::STATUS_APPROVED, PaymentRequest::STATUS_PAID], true) && ($pr->approved_by_user_id || $pr->approved_at)) {
            $out['approved'] = $this->entry($pr->approved_by_user_id, $pr->approved_at, 'The approval of the payment request is not fully recorded.');
        }

        if ($record->verification_status === Payment::VERIFICATION_VERIFIED) {
            $out['verified'] = $this->entry($record->verified_by_user_id, $record->verified_on, 'The verification is not fully recorded on this payment.');
        }

        return $out;
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
            ['role' => 'prepared_by', 'action' => 'prepared', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'paid_by', 'action' => 'paid', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
            ['role' => 'verified_by', 'action' => 'verified', 'slot' => 4, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
