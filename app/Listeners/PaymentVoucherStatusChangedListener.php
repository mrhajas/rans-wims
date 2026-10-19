<?php

namespace App\Listeners;

use App\Events\PaymentVoucherStatusChanged;
use App\Models\PaymentVoucher;
use App\Models\User;
use App\Services\PaymentVoucherNotifier;

/**
 * Hands the status change to the Payment Vouchers workflow page's settings (Who Gets Notified / Messages). The starting values are the
 * old behaviour: Submitted tells the people who hold approve-payment-vouchers, Approved tells the holders of authorise-payment-vouchers
 * (neither includes the person who prepared the voucher), and Authorised / Rejected / Cancelled tell the preparer.
 */
class PaymentVoucherStatusChangedListener
{
    public function handle(PaymentVoucherStatusChanged $event): void
    {
        $voucher = $event->paymentVoucher;
        $no = $voucher->pv_number;

        match ($voucher->status) {
            PaymentVoucher::STATUS_SUBMITTED => $this->notifyHolders('submitted', $voucher, 'Payment Voucher Submitted for Approval', 'Payment Voucher ' . $no . ' is awaiting your approval.'),
            PaymentVoucher::STATUS_APPROVED => $this->notifyHolders('approved', $voucher, 'Payment Voucher Approved', 'Payment Voucher ' . $no . ' has been approved and is awaiting your authorisation.'),
            PaymentVoucher::STATUS_AUTHORISED => $this->notifyPreparer('authorised', $voucher, 'Payment Voucher Authorised', 'Your Payment Voucher ' . $no . ' has been authorised.'),
            PaymentVoucher::STATUS_REJECTED => $this->notifyPreparer('rejected', $voucher, 'Payment Voucher Rejected', 'Your Payment Voucher ' . $no . ' was rejected.' . ($voucher->approval_remarks ? ' Reason: ' . $voucher->approval_remarks : ''), $voucher->approval_remarks),
            PaymentVoucher::STATUS_CANCELLED => $this->notifyPreparer('cancelled', $voucher, 'Payment Voucher Cancelled', 'Your Payment Voucher ' . $no . ' has been cancelled.'),
            default => null,
        };
    }

    /**
     * The people who hold the permission (through a role or a direct grant) are the event's audience on the workflow page. The preparer
     * is passed as the "actor" only so that he or she is left out, as before; the real actor is still shown as "done by".
     */
    private function notifyHolders(string $event, PaymentVoucher $voucher, string $title, string $message): void
    {
        $preparer = $voucher->preparedBy ?? User::find($voucher->prepared_by_user_id);
        PaymentVoucherNotifier::fire($event, $voucher, $preparer, $title, $message, [
            'owner_user_id' => 0, 'remarks' => (string) $voucher->approval_remarks,
            'values' => ['actor' => auth()->user()?->name ?? ''],
        ]);
    }

    private function notifyPreparer(string $event, PaymentVoucher $voucher, string $title, string $message, ?string $remarks = null): void
    {
        PaymentVoucherNotifier::fire($event, $voucher, auth()->user(), $title, $message, ['remarks' => (string) $remarks]);
    }
}
