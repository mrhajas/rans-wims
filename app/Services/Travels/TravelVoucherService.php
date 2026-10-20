<?php

namespace App\Services\Travels;

use App\Models\Contact;
use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherType;
use App\Models\TravelAgentPayment;
use App\Support\NumberToWords;
use App\Support\PaymentVoucherNumbers;
use App\Support\ReferenceLock;
use Illuminate\Validation\ValidationException;

/**
 * Payments to ticket agents and visa issuers go ONLY through a Payment Voucher. This makes ONE Draft voucher (payee = the agent's contact, one line) from the
 * agent's balance in Travels, plus the "Pending Voucher" payment that reserves the amount. The voucher is then submitted, approved, authorised and marked paid
 * in the Payment Vouchers module; marking it paid turns the payment into "Paid", which is what reduces the balance. A rejected / cancelled / deleted voucher
 * releases the amount. A foreign-currency balance is paid in TZS at the rate typed: the voucher carries the TZS total, the balance falls by the foreign amount.
 */
class TravelVoucherService
{
    public const VOUCHER_TYPE = 'Travel Agent';

    /** Call inside a DB transaction. Throws a validation error on $errorKey when the amount is more than what is available (balance minus what other waiting vouchers reserve). */
    public static function create(Contact $contact, string $currency, float $amount, float $rate, ?string $notes, int $userId, ?int $travelId = null, string $errorKey = 'amount'): TravelAgentPayment
    {
        $contact = Contact::whereKey($contact->id)->lockForUpdate()->firstOrFail();
        $currency = strtoupper($currency);
        $amount = round($amount, 2);
        $rate = $currency === 'TZS' ? 1.0 : round($rate, 6);
        $available = TravelLedger::available($contact->id, $currency);
        if ($amount <= 0 || $amount > $available + 0.001) {
            throw ValidationException::withMessages([$errorKey => __('A payment to :name can be at most the balance available (:c :balance).', ['name' => $contact->name, 'c' => $currency, 'balance' => number_format(max(0, $available), 2)])]);
        }
        if ($currency !== 'TZS' && $rate <= 0) {
            throw ValidationException::withMessages(['exchange_rate' => __('Enter the exchange rate: how many TZS one :c is worth.', ['c' => $currency])]);
        }
        $voucherAmount = round($amount * $rate, 2);
        $payment = ReferenceLock::run('travel_payments', fn () => TravelAgentPayment::create([
            'reference' => TravelAgentPayment::nextReference(), 'contact_id' => $contact->id, 'travel_id' => $travelId, 'currency' => $currency, 'amount' => $amount, 'exchange_rate' => $rate, 'voucher_amount' => $voucherAmount,
            'status' => TravelAgentPayment::PENDING_VOUCHER, 'requested_on' => now()->toDateString(), 'notes' => $notes,
        ]));
        $voucher = PaymentVoucherNumbers::createVoucher([
            'type' => self::VOUCHER_TYPE,
            'payee_type' => PaymentVoucher::PAYEE_TYPE_EXTERNAL,
            'payee_contact_id' => $contact->id,
            'payee_name' => $contact->name,
            'voucher_date' => now()->toDateString(),
            'total_amount' => $voucherAmount,
            'amount_in_words' => NumberToWords::convert($voucherAmount),
            'status' => PaymentVoucher::STATUS_DRAFT,
            'prepared_by_user_id' => $userId,
            'internal_notes' => __('Travel agent payment :r', ['r' => $payment->reference]),
        ]);
        PaymentVoucherType::firstOrCreate(['text' => self::VOUCHER_TYPE]);
        $payment->forceFill(['payment_voucher_id' => $voucher->id])->save();
        $voucher->items()->create([
            'line_number' => 1,
            'travel_agent_payment_id' => $payment->id,
            'particulars' => PaymentVoucher::particularsText(self::particulars($payment, $contact, $notes)),
            'amount' => $voucherAmount,
        ]);
        log_activity(__('Payment Voucher created for a travel agent.'), ['pv_number' => $voucher->pv_number, 'contact_id' => $contact->id, 'amount' => $voucherAmount, 'payment' => $payment->reference], $voucher, 'Payment Voucher');
        log_activity(__('Travel agent payment sent to a Payment Voucher.'), ['pv_number' => $voucher->pv_number, 'agent' => $contact->name, 'amount' => $amount, 'currency' => $currency, 'payment' => $payment->reference], $payment, 'Travels');

        $payment->setRelation('voucher', $voucher);
        \DB::afterCommit(fn () => TravelNotifier::firePayment('payment_requested', $payment, auth()->user(), $notes ?: ''));

        return $payment;
    }

    private static function particulars(TravelAgentPayment $p, Contact $c, ?string $notes): string
    {
        $foreign = $p->currency !== 'TZS' ? ' (' . $p->currency . ' ' . number_format((float) $p->amount, 2) . ' at ' . rtrim(rtrim(number_format((float) $p->exchange_rate, 6), '0'), '.') . ')' : '';

        return 'Travel agent payment ' . $p->reference . ', ' . $c->name . $foreign . ($notes ? ' - ' . $notes : '');
    }

    /**
     * Call inside a DB transaction, AFTER the voucher row was updated. Keeps the payment in step when a Draft / Rejected voucher is edited: its amount follows the voucher
     * total (never more than is available), a rejected voucher that released its payment reserves it again, and the agent cannot be swapped.
     * Returns the id of the linked payment so the voucher line can point at it, or null when nothing is linked.
     */
    public static function syncOnEdit(PaymentVoucher $voucher, bool $wasTravelVoucher, float $total): ?int
    {
        $payment = TravelAgentPayment::query()->where('payment_voucher_id', $voucher->id)->whereIn('status', [TravelAgentPayment::PENDING_VOUCHER, TravelAgentPayment::RELEASED])->lockForUpdate()->first();
        if (! $payment) {
            return null;
        }
        $total = round($total, 2);
        if ((int) $voucher->payee_contact_id !== (int) $payment->contact_id) {
            throw ValidationException::withMessages(['payee_contact_id' => __('The payee of a travel agent voucher cannot be changed. Make a new payment for the other agent.')]);
        }
        Contact::whereKey($payment->contact_id)->lockForUpdate()->first();
        $rate = (float) $payment->exchange_rate ?: 1.0;
        $amount = round($total / $rate, 2);
        $reserved = $payment->status === TravelAgentPayment::PENDING_VOUCHER ? (float) $payment->amount : 0.0; // its own amount is already reserved
        $limit = TravelLedger::available((int) $payment->contact_id, $payment->currency) + $reserved;
        if ($total <= 0 || $amount > $limit + 0.001) {
            throw ValidationException::withMessages(['items' => __('This voucher can be at most the balance available (:c :balance).', ['c' => $payment->currency, 'balance' => number_format(max(0, $limit) * $rate, 2) . ' TZS'])]);
        }
        $changed = abs($amount - (float) $payment->amount) > 0.001 || $payment->status === TravelAgentPayment::RELEASED;
        $payment->update(['amount' => $amount, 'voucher_amount' => $total, 'status' => TravelAgentPayment::PENDING_VOUCHER, 'released_at' => null, 'release_reason' => null]);
        if ($changed) {
            log_activity(__('Payment Voucher edited, travel agent payment updated.'), ['pv_number' => $voucher->pv_number, 'payment' => $payment->reference, 'amount' => $amount], $payment, 'Travels');
        }

        return $payment->id;
    }

    /** The voucher was rejected, cancelled or deleted: the reserved amount is released (the payment stays on record as history). */
    public static function release(PaymentVoucher $voucher, string $reason): void
    {
        TravelAgentPayment::query()->where('payment_voucher_id', $voucher->id)->pendingVoucher()->get()->each(function ($p) use ($voucher, $reason) {
            $p->update(['status' => TravelAgentPayment::RELEASED, 'released_at' => now(), 'release_reason' => $reason]);
            log_activity(__(':r Payment Voucher :pv, travel agent payment released.', ['r' => $reason, 'pv' => $voucher->pv_number]), ['payment' => $p->reference, 'amount' => (string) $p->amount, 'currency' => $p->currency], $p, 'Travels');
            \DB::afterCommit(fn () => TravelNotifier::firePayment('payment_released', $p->fresh(), auth()->user(), (string) $reason));
        });
    }

    /** A rejected voucher is sent again WITHOUT being edited: its payment holds its amount again, or the voucher is refused (so nothing is paid that the agent account does not know about). */
    public static function resubmit(PaymentVoucher $voucher): void
    {
        if ($voucher->type !== self::VOUCHER_TYPE) {
            return;
        }
        $p = TravelAgentPayment::query()->where('payment_voucher_id', $voucher->id)->whereIn('status', [TravelAgentPayment::PENDING_VOUCHER, TravelAgentPayment::RELEASED])->lockForUpdate()->first();
        if (! $p) {
            throw ValidationException::withMessages(['status' => __('This voucher is no longer linked to a travel agent payment, so it cannot be sent.')]);
        }
        if ($p->status !== TravelAgentPayment::RELEASED) {
            return;
        }
        Contact::whereKey($p->contact_id)->lockForUpdate()->first();
        if ((float) $p->amount > TravelLedger::available((int) $p->contact_id, $p->currency) + 0.001) {
            throw ValidationException::withMessages(['items' => __('The agent no longer has this much available (:c :a), so the voucher cannot be sent again.', ['c' => $p->currency, 'a' => number_format(max(0, TravelLedger::available((int) $p->contact_id, $p->currency)), 2)])]);
        }
        $p->update(['status' => TravelAgentPayment::PENDING_VOUCHER, 'released_at' => null, 'release_reason' => null]);
        log_activity(__('Payment Voucher sent again, travel agent payment reserved again.'), ['pv_number' => $voucher->pv_number, 'payment' => $p->reference, 'amount' => (string) $p->amount], $p, 'Travels');
    }

    /** The voucher was marked paid: the payment is now real and reduces the balance. Call inside the paid transaction. */
    public static function markPaid(PaymentVoucher $voucher): void
    {
        if (! TravelAgentPayment::query()->where('payment_voucher_id', $voucher->id)->pendingVoucher()->exists() && $voucher->type === self::VOUCHER_TYPE) {
            throw ValidationException::withMessages(['payment_reference' => __('This voucher is not waiting on a travel agent payment (it was released). Make the payment again from the agent account before paying.')]);
        }
        foreach (TravelAgentPayment::query()->where('payment_voucher_id', $voucher->id)->pendingVoucher()->get() as $p) {
            $p->update(['status' => TravelAgentPayment::PAID, 'paid_on' => $voucher->paid_at ? $voucher->paid_at->toDateString() : now()->toDateString(), 'method' => $voucher->payment_method,
                'payment_reference' => $voucher->payment_reference ?: $voucher->pv_number, 'paid_by_user_id' => $voucher->paid_by_user_id]);
            log_activity(__('Travel agent payment completed via Payment Voucher.'), ['pv_number' => $voucher->pv_number, 'payment' => $p->reference, 'amount' => (string) $p->amount, 'currency' => $p->currency], $p, 'Travels');
            \DB::afterCommit(fn () => TravelNotifier::firePayment('payment_paid', $p->fresh(), auth()->user()));
        }
    }
}
