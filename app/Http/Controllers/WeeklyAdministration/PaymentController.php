<?php

namespace App\Http\Controllers\WeeklyAdministration;

use App\Http\Controllers\Controller;
use App\Models\AdminDocument;
use App\Models\AdminDocumentPayment;
use App\Services\Administration\AdminNotifier;
use App\Services\Administration\AdminPaymentService;
use Illuminate\Http\Request;

/**
 * Paying an authorized Administration document: a person with the permission marks it paid (all of it, one line at a time or part of a line, to a chosen user or
 * staff member), or makes a Payment Voucher for it (approved and paid in the Payment Vouchers module). Every payment, voucher and reversal is logged and alerted.
 */
class PaymentController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function view(AdminDocument $d): void
    {
        abort_unless(auth()->user()->can('read-weekly-administration') && AdminDocument::query()->visibleTo(auth()->user())->whereKey($d->id)->exists(), 404);
    }

    private function body(Request $request, bool $direct): array
    {
        $rules = ['mode' => 'required|in:all,lines', 'payee' => 'required|string|max:20', 'lines' => 'nullable|array|max:600', 'lines.*.key' => 'required_with:lines|string|max:30', 'lines.*.amount' => 'nullable|numeric|min:0|max:999999999999', 'notes' => 'nullable|string|max:1000'];
        if ($direct) {
            $rules += ['paid_on' => 'nullable|date|before_or_equal:today', 'method' => 'nullable|string|max:100', 'payment_reference' => 'nullable|string|max:190'];
        }

        return $request->validate($rules, ['payee.required' => __('Choose who is paid: a user or a staff member.')]);
    }

    /** Marks lines paid. */
    public function store(Request $request, AdminDocument $document)
    {
        abort_unless($request->user()->can('mark-paid-weekly-administration'), 403);
        $this->view($document);
        $d = $this->body($request, true);
        $p = AdminPaymentService::record($document, $d['mode'], (array) ($d['lines'] ?? []), $d['payee'], ['paid_on' => $d['paid_on'] ?? null, 'method' => $d['method'] ?? null, 'payment_reference' => $d['payment_reference'] ?? null, 'notes' => $d['notes'] ?? null], $request->user());
        $doc = $p->document->fresh();
        AdminNotifier::firePayment($doc->payment_status === 'Paid' ? 'fully_paid' : 'partially_paid', $doc, $p, $request->user());

        return back()->with('message', $doc->payment_status === 'Paid' ? __(':r is now fully paid.', ['r' => $doc->reference]) : __(':a marked paid to :n. Balance left: :b.', ['a' => number_format((float) $p->total_amount, 2), 'n' => $p->paid_to_name, 'b' => number_format(max(0, (float) $doc->total_amount - (float) $doc->paid_total), 2)]));
    }

    /** Makes a Payment Voucher for the chosen lines. */
    public function voucher(Request $request, AdminDocument $document)
    {
        abort_unless($request->user()->can('create-payment-voucher-from-administration'), 403);
        $this->view($document);
        $d = $this->body($request, false);
        $p = AdminPaymentService::requestVoucher($document, $d['mode'], (array) ($d['lines'] ?? []), $d['payee'], $d['notes'] ?? null, $request->user());
        AdminNotifier::firePayment('voucher_requested', $p->document->fresh(), $p, $request->user());

        return redirect()->route('payment-vouchers.show', $p->payment_voucher_id)->with('message', __('Payment Voucher :v made for :a. It now waits for approval in the Payment Vouchers module.', ['v' => optional($p->voucher)->pv_number, 'a' => number_format((float) $p->total_amount, 2)]));
    }

    /** Reverses a payment that was marked paid (with a reason). */
    public function reverse(Request $request, AdminDocumentPayment $payment)
    {
        abort_unless($request->user()->can('reverse-payments-weekly-administration'), 403);
        $this->view($payment->document);
        $reason = trim($request->validate(['reason' => 'required|string|max:1000'])['reason']);
        $p = AdminPaymentService::reverse($payment, $reason, $request->user());
        AdminNotifier::firePayment('payment_reversed', $p->document->fresh(), $p, $request->user(), $reason);

        return back()->with('message', __('Payment :r reversed. Its lines are unpaid again.', ['r' => $p->reference]));
    }
}
