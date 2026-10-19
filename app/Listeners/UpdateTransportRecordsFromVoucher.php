<?php

namespace App\Listeners;

use App\Events\PaymentVoucherStatusChanged;
use App\Models\PaymentVoucher;
use App\Models\TransportRecord;

/**
 * Keeps every Transport Record linked to a Payment Voucher
 * (transport_records.payment_voucher_id) in sync with that voucher's own
 * status — moving linked trips from Unpaid to Pending Payment once the
 * voucher leaves Draft, and back the other way (unlinked, reset to Unpaid)
 * if the voucher is ever Rejected or Cancelled instead of paid. Without that
 * second half, a trip whose voucher got rejected/cancelled stayed wedged in
 * Pending Payment forever — still linked to a dead voucher, so markPaid()
 * refused it (payment_voucher_id must be null) and createPaymentVoucher()
 * refused it too (already has a voucher), with no way to ever pay it. The
 * "Paid" transition is handled separately, by a direct inline sync block in
 * PaymentVoucherController::recordPayment() (mirroring the existing
 * LabourList sync already there), since PaymentVoucherStatusChanged
 * deliberately never fires for that step — see that method's own comment.
 */
class UpdateTransportRecordsFromVoucher
{
    public function handle(PaymentVoucherStatusChanged $event): void
    {
        $voucher = $event->paymentVoucher;

        if (in_array($voucher->status, [PaymentVoucher::STATUS_REJECTED, PaymentVoucher::STATUS_CANCELLED], true)) {
            // Loading & Unloading payments waiting on this voucher are released: the amount is available again.
            \App\Models\TransportLoadingPayment::pendingVoucher()->where('payment_voucher_id', $voucher->id)->get()->each(function ($loadingPayment) use ($voucher) {
                log_activity(__(':status Payment Voucher :pv, Loading & Unloading payment released.', ['status' => $voucher->status, 'pv' => $voucher->pv_number]), ['contact_id' => $loadingPayment->contact_id, 'amount' => $loadingPayment->amount], $voucher, 'Transport Loading');
                $loadingPayment->delete();
            });

            // Excludes STATUS_PAID defensively — in practice a trip can only
            // reach Paid via recordPayment(), which requires the voucher to
            // already be Authorised, and a voucher can no longer be
            // rejected/cancelled once Authorised (see cancel()'s own status
            // guard), so this should never match a Paid trip today.
            $trips = TransportRecord::where('payment_voucher_id', $voucher->id)
                ->where('payment_status', '!=', TransportRecord::STATUS_PAID)
                ->get();

            foreach ($trips as $trip) {
                $trip->update([
                    'payment_voucher_id' => null,
                    // Recomputed from paid_amount, not blindly reset to
                    // Unpaid — a trip can already carry a payment made
                    // before this voucher existed, and that payment must
                    // stay reflected instead of being silently erased from
                    // its status (see TransportRecord::recomputedPaymentStatus()).
                    'payment_status' => $trip->recomputedPaymentStatus(),
                ]);
                log_activity(
                    __(':status Payment Voucher :pv, trip unlinked and reset to Unpaid.', ['status' => $voucher->status, 'pv' => $voucher->pv_number]),
                    ['trip_number' => $trip->trip_number, 'pv_number' => $voucher->pv_number],
                    $trip,
                    'Transport Record'
                );
            }

            return;
        }

        if (! in_array($voucher->status, [PaymentVoucher::STATUS_SUBMITTED, PaymentVoucher::STATUS_APPROVED, PaymentVoucher::STATUS_AUTHORISED], true)) {
            return;
        }

        TransportRecord::where('payment_voucher_id', $voucher->id)
            ->where('payment_status', TransportRecord::STATUS_UNPAID)
            ->update(['payment_status' => TransportRecord::STATUS_PENDING_PAYMENT]);
    }
}
