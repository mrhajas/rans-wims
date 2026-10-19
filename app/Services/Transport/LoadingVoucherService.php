<?php

namespace App\Services\Transport;

use App\Models\Contact;
use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherType;
use App\Models\TransportLoadingPayment;
use App\Support\NumberToWords;
use App\Support\PaymentVoucherNumbers;
use Illuminate\Validation\ValidationException;

/**
 * Makes ONE Draft Payment Voucher for ONE Loading & Unloading person (payee = their contact, one line for the amount) and the
 * "Pending Voucher" payment that reserves that amount in their ledger. Single voucher, bulk generation (a separate voucher
 * per person) and the sample-data command all use this, so they behave identically.
 */
class LoadingVoucherService
{
    public const VOUCHER_TYPE = 'Loading & Unloading';

    /**
     * Call inside a DB transaction. Throws a validation error on $errorKey when the amount is more than what is available
     * (balance minus what is already waiting on another voucher).
     */
    public static function create(Contact $contact, float $amount, ?string $notes, int $userId, string $errorKey = 'amount', ?string $internalNote = null): PaymentVoucher
    {
        $contact = Contact::whereKey($contact->id)->lockForUpdate()->firstOrFail();
        $amount = round($amount, 2);
        $available = LoadingLedger::availableOf($contact->id);
        if ($amount <= 0 || $amount > $available + 0.001) {
            throw ValidationException::withMessages([$errorKey => __('A voucher for :name can be at most the balance available (:balance).', ['name' => $contact->name, 'balance' => number_format($available, 2)])]);
        }

        $voucher = PaymentVoucherNumbers::createVoucher([
            'type' => self::VOUCHER_TYPE,
            'payee_type' => PaymentVoucher::PAYEE_TYPE_EXTERNAL,
            'payee_contact_id' => $contact->id,
            'payee_name' => $contact->name,
            'voucher_date' => now()->toDateString(),
            'total_amount' => $amount,
            'amount_in_words' => NumberToWords::convert($amount),
            'status' => PaymentVoucher::STATUS_DRAFT,
            'prepared_by_user_id' => $userId,
            'internal_notes' => $internalNote,
        ]);
        PaymentVoucherType::firstOrCreate(['text' => self::VOUCHER_TYPE]);

        $payment = TransportLoadingPayment::create([
            'contact_id' => $contact->id,
            'amount' => $amount,
            'status' => TransportLoadingPayment::PENDING_VOUCHER,
            'payment_voucher_id' => $voucher->id,
            'payment_date' => now()->toDateString(),
            'notes' => $internalNote ?: $notes,
        ]);

        $voucher->items()->create([
            'line_number' => 1,
            'transport_loading_payment_id' => $payment->id,
            'particulars' => PaymentVoucher::particularsText('Loading & Unloading, ' . $contact->name . ' (balance up to ' . now()->format('d M Y') . ')' . ($notes ? ' - ' . $notes : '')),
            'amount' => $amount,
        ]);

        log_activity(__('Payment Voucher created for Loading & Unloading personnel.'), ['pv_number' => $voucher->pv_number, 'contact_id' => $contact->id, 'amount' => $amount], $voucher, 'Payment Voucher');
        log_activity(__('Loading & Unloading payment sent to a Payment Voucher.'), ['pv_number' => $voucher->pv_number, 'contact_id' => $contact->id, 'amount' => $amount], auth()->user() ?? $voucher, 'Transport Loading');

        return $voucher;
    }

    /**
     * Call inside a DB transaction, AFTER the voucher row was updated. Keeps the ledger in step when a Draft / Rejected
     * Loading & Unloading voucher is edited: the waiting payment follows the voucher's total (it may not exceed what is
     * available), a rejected voucher that released its payment reserves it again, and the person cannot be swapped.
     * Returns the id of the linked payment so the voucher line can point at it, or null when nothing is linked.
     */
    public static function syncOnEdit(PaymentVoucher $voucher, bool $wasLoadingVoucher, float $total): ?int
    {
        $payment = TransportLoadingPayment::pendingVoucher()->where('payment_voucher_id', $voucher->id)->lockForUpdate()->first();
        $total = round($total, 2);

        if ($payment) {
            if ((int) $voucher->payee_contact_id !== (int) $payment->contact_id) {
                throw ValidationException::withMessages(['payee_contact_id' => __('The payee of a Loading & Unloading voucher cannot be changed. Create a new voucher for the other person.')]);
            }
            Contact::whereKey($payment->contact_id)->lockForUpdate()->first();
            $limit = LoadingLedger::availableOf($payment->contact_id) + (float) $payment->amount; // its own amount is already reserved
            if ($total <= 0 || $total > $limit + 0.001) {
                throw ValidationException::withMessages(['items' => __('This voucher can be at most the balance available (:balance).', ['balance' => number_format($limit, 2)])]);
            }
            if (abs($total - (float) $payment->amount) > 0.001) {
                log_activity(__('Payment Voucher edited, Loading & Unloading payment amount updated.'), ['pv_number' => $voucher->pv_number, 'contact_id' => $payment->contact_id, 'from' => (float) $payment->amount, 'to' => $total], $voucher, 'Transport Loading');
                $payment->update(['amount' => $total]);
            }

            return $payment->id;
        }

        // A rejected voucher released its payment; once edited and sent again it must reserve the amount again.
        if ($wasLoadingVoucher && $voucher->payee_contact_id && in_array((int) $voucher->payee_contact_id, LoadingLedger::workerIds(), true)
            && TransportLoadingPayment::onlyTrashed()->where('payment_voucher_id', $voucher->id)->exists()) {
            Contact::whereKey($voucher->payee_contact_id)->lockForUpdate()->first();
            $available = LoadingLedger::availableOf((int) $voucher->payee_contact_id);
            if ($total <= 0 || $total > $available + 0.001) {
                throw ValidationException::withMessages(['items' => __('This voucher can be at most the balance available (:balance).', ['balance' => number_format($available, 2)])]);
            }
            $payment = TransportLoadingPayment::create([
                'contact_id' => $voucher->payee_contact_id,
                'amount' => $total,
                'status' => TransportLoadingPayment::PENDING_VOUCHER,
                'payment_voucher_id' => $voucher->id,
                'payment_date' => now()->toDateString(),
            ]);
            log_activity(__('Payment Voucher edited, Loading & Unloading payment reserved again.'), ['pv_number' => $voucher->pv_number, 'contact_id' => $payment->contact_id, 'amount' => $total], $voucher, 'Transport Loading');

            return $payment->id;
        }

        return null;
    }
}
