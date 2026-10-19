<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\SalaryNotificationRule;
use App\Models\SalaryPayment;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use App\Models\SalaryVoucher;
use App\Notifications\AppNotification;
use App\Services\SalarySmsNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SalaryPaymentController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function pay(Request $request, $id)
    {
        $this->authorize('pay-salary-periods');

        $record = SalaryRecord::with(['staff:id,name', 'period'])->findOrFail($id);
        $this->authorize('pay', $record->period);

        return Inertia::render('Salary/Payments/Pay', [
            'record' => [
                'id' => $record->id,
                'staff_name' => $record->staff->name,
                'period_id' => $record->salary_period_id,
                'period_label' => $record->period->label,
                'current_payable' => (float) $record->current_payable,
                'total_paid' => $record->totalPaid(),
                'remaining_balance' => $record->remainingBalance(),
            ],
        ]);
    }

    public function store(Request $request, $id)
    {
        $this->authorize('pay-salary-periods');

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string|max:100',
            'payment_reference' => 'nullable|string|max:190',
            'notes' => 'nullable|string|max:1000',
        ]);

        $record = DB::transaction(function () use ($id, $data, $request) {
            $record = SalaryRecord::whereKey($id)->lockForUpdate()->firstOrFail();
            $period = $record->period;
            $this->authorize('pay', $period);

            $remaining = $record->remainingBalance();
            abort_if($remaining <= 0, 422, 'This salary record has already been paid in full.');
            abort_if($data['amount'] > $remaining, 422, "Payment cannot exceed the remaining balance of {$remaining}.");

            // A voucher generated for this record (individually or as part
            // of a bulk batch) was previously never actually linked to the
            // payment that settled it — salary_payments.salary_voucher_id
            // and the voucher's own payment_date/method/paid_by_user_id
            // stayed null forever. Linked here now that a real payment
            // against it exists.
            $voucher = SalaryVoucher::where('type', SalaryVoucher::TYPE_INDIVIDUAL)
                ->where('salary_period_id', $record->salary_period_id)
                ->where('staff_id', $record->staff_id)
                ->first();

            SalaryPayment::create([
                'salary_record_id' => $record->id,
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'paid_by_user_id' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
                'salary_voucher_id' => $voucher?->id,
            ]);

            $record = $record->fresh();
            $newRemaining = $record->remainingBalance();
            $record->update(['status' => $newRemaining <= 0 ? SalaryRecord::STATUS_PAID : SalaryRecord::STATUS_PARTIALLY_PAID]);

            if ($voucher && $newRemaining <= 0) {
                $voucher->update([
                    'payment_date' => $data['payment_date'],
                    'payment_method' => $data['payment_method'] ?? $voucher->payment_method,
                    'paid_by_user_id' => $request->user()->id,
                ]);
            }

            $this->syncPeriodStatus($period);

            // Signatories finalisation guard: a payment that settles the voucher is the final step; a required signature set to block it rolls the payment back.
            abort_if(($missing = $this->missingSignatures($record)) !== null, 422, $missing ?? '');

            return $record;
        });

        log_activity(__('Salary payment of :amount recorded.', ['amount' => $data['amount']]), ['remaining' => $record->remainingBalance()], $record->period, 'Salary');

        $this->notifyStaff($record, (float) $data['amount']);

        return back()->with('message', __('Payment of :amount recorded. Remaining balance: :remaining.', ['amount' => number_format($data['amount'], 2), 'remaining' => number_format($record->remainingBalance(), 2)]));
    }

    /**
     * Pays every record in a period that still has a remaining balance, in
     * full, in one action — same per-record rules as store() (remaining-
     * balance capping, voucher linking, status roll-up), just looped inside
     * one transaction instead of requiring one click per employee.
     * Notifications are sent after the transaction commits, exactly like
     * store() does, so a later record's failure can never leave an earlier
     * one notified-but-rolled-back.
     */
    public function bulkStore(Request $request, $periodId)
    {
        $this->authorize('pay-salary-periods');

        $data = $request->validate([
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string|max:100',
            'payment_reference' => 'nullable|string|max:190',
            'notes' => 'nullable|string|max:1000',
        ]);

        $period = SalaryPeriod::findOrFail($periodId);
        $this->authorize('pay', $period);

        $blocked = [];
        $paid = DB::transaction(function () use ($periodId, $data, $request, &$blocked) {
            $period = SalaryPeriod::whereKey($periodId)->lockForUpdate()->firstOrFail();
            $paid = collect();

            foreach ($period->records()->get() as $record) {
                // each record in its own savepoint, so one record held back by the Signatories guard never undoes the others
                try {
                    DB::transaction(function () use ($record, $data, $request, $paid) {
                        $locked = SalaryRecord::whereKey($record->id)->lockForUpdate()->first();
                        $remaining = $locked->remainingBalance();

                        if ($remaining <= 0) {
                            return;
                        }

                        $voucher = SalaryVoucher::where('type', SalaryVoucher::TYPE_INDIVIDUAL)
                            ->where('salary_period_id', $locked->salary_period_id)
                            ->where('staff_id', $locked->staff_id)
                            ->first();

                        SalaryPayment::create([
                            'salary_record_id' => $locked->id,
                            'amount' => $remaining,
                            'payment_date' => $data['payment_date'],
                            'payment_method' => $data['payment_method'] ?? null,
                            'payment_reference' => $data['payment_reference'] ?? null,
                            'paid_by_user_id' => $request->user()->id,
                            'notes' => $data['notes'] ?? null,
                            'salary_voucher_id' => $voucher?->id,
                        ]);

                        $locked->update(['status' => SalaryRecord::STATUS_PAID]);

                        if ($voucher) {
                            $voucher->update([
                                'payment_date' => $data['payment_date'],
                                'payment_method' => $data['payment_method'] ?? $voucher->payment_method,
                                'paid_by_user_id' => $request->user()->id,
                            ]);
                        }

                        // Signatories finalisation guard (see store()): this record is left unpaid when a required signature is missing.
                        if (($missing = $this->missingSignatures($locked->fresh())) !== null) {
                            throw \Illuminate\Validation\ValidationException::withMessages(['signatures' => [$missing]]);
                        }

                        $paid->push(['record' => $locked->fresh(), 'amount' => $remaining]);
                    });
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $blocked[] = $e->errors()['signatures'][0] ?? '';
                }
            }

            $this->syncPeriodStatus($period);

            return $paid;
        });

        log_activity(
            __(':count salary record(s) paid in bulk.', ['count' => $paid->count()]),
            ['period_id' => $periodId, 'batch_reference' => $period->batch_reference],
            $period,
            'Salary'
        );

        foreach ($paid as $entry) {
            $this->notifyStaff($entry['record'], $entry['amount']);
        }
        if ($paid->isNotEmpty()) {
            \App\Services\SalaryNotifier::fire('bulk_paid', $period, $request->user(), __('Salary Period Paid In Bulk'), 'Salary period ' . $period->label . ' (' . $period->batch_reference . '): ' . $paid->count() . ' salary payment(s) were recorded in bulk by ' . $request->user()->name . '.', ['period' => $period->label, 'batch' => $period->batch_reference, 'amount' => \App\Services\SalaryNotifier::money($paid->sum('amount'))]);
        }

        $heldBack = $blocked ? ' ' . __(':n record(s) were left unpaid because required signatures are missing: :why', ['n' => count($blocked), 'why' => implode(' | ', $blocked)]) : '';

        if ($paid->isEmpty()) {
            return back()->with('message', ($blocked ? '' : __('Nothing to pay, every record in this period is already settled.')) . ltrim($heldBack));
        }

        return back()->with('message', __(':count salary payment(s) recorded, totalling :total.', [
            'count' => $paid->count(),
            'total' => number_format($paid->sum('amount'), 2),
        ]) . $heldBack);
    }

    /** A mis-entered payment is reversed by creating a new negative-amount row that references it (SalaryPayment::reverses()), never by editing/deleting the original — matches the model's own documented "immutable ledger" design. */
    public function reverse(Request $request, $paymentId)
    {
        $this->authorize('pay-salary-periods');

        $data = $request->validate(['reason' => 'required|string|max:1000']);

        $record = DB::transaction(function () use ($paymentId, $data, $request) {
            $payment = SalaryPayment::whereKey($paymentId)->lockForUpdate()->firstOrFail();
            abort_if((float) $payment->amount < 0, 422, 'A reversal cannot itself be reversed.');
            abort_if(SalaryPayment::where('reverses_payment_id', $payment->id)->exists(), 422, 'This payment has already been reversed.');

            $record = SalaryRecord::whereKey($payment->salary_record_id)->lockForUpdate()->firstOrFail();
            $period = $record->period;
            $this->authorize('pay', $period);

            SalaryPayment::create([
                'salary_record_id' => $record->id,
                'amount' => -abs((float) $payment->amount),
                'payment_date' => now()->toDateString(),
                'payment_method' => $payment->payment_method,
                'payment_reference' => 'Reversal of payment #' . $payment->id,
                'paid_by_user_id' => $request->user()->id,
                'notes' => $data['reason'],
                'reverses_payment_id' => $payment->id,
            ]);

            $record = $record->fresh();
            $remaining = $record->remainingBalance();
            $record->update([
                'status' => $remaining <= 0 ? SalaryRecord::STATUS_PAID : ($record->totalPaid() > 0 ? SalaryRecord::STATUS_PARTIALLY_PAID : SalaryRecord::STATUS_DRAFT),
            ]);

            $this->syncPeriodStatus($period);

            return $record;
        });

        log_activity(__('Salary payment #:id reversed.', ['id' => $paymentId]), ['reason' => $data['reason']], $record->period, 'Salary');

        \App\Services\SalaryNotifier::fire('payment_reversed', $record, $request->user(), __('Salary Payment Reversed'), 'A salary payment of ' . optional($record->staff)->name . ' for ' . optional($record->period)->label . ' was reversed by ' . $request->user()->name . '. Reason: ' . $data['reason'], ['period' => optional($record->period)->label, 'batch' => optional($record->period)->batch_reference], null, ['remarks' => $data['reason']]);

        return back()->with('message', __('Payment reversed.'));
    }

    /**
     * Signatories finalisation guard. The final step of a salary voucher is the payment that settles it. Called inside the payment
     * transaction AFTER the payment is written: returns a message naming the missing signatures when the document setup marks one as
     * required AND blocking (the caller then rolls the payment back), otherwise null. Checks the individual voucher and the bulk master.
     */
    private function missingSignatures(SalaryRecord $record): ?string
    {
        $resolver = app(\App\Services\Signatories\SignatureResolver::class);
        $vouchers = SalaryVoucher::where('salary_period_id', $record->salary_period_id)
            ->where(fn ($q) => $q->where('type', SalaryVoucher::TYPE_BULK)->orWhere(fn ($w) => $w->where('type', SalaryVoucher::TYPE_INDIVIDUAL)->where('staff_id', $record->staff_id)))
            ->get();
        $lines = [];
        foreach ($vouchers as $v) {
            if ($resolver->adapter('salary_voucher')?->state($v) !== 'final') {
                continue; // a part payment, or a bulk voucher that is not fully settled yet, is not the final step
            }
            foreach ($resolver->blockers('salary_voucher', $v) as $b) {
                $lines[] = $v->voucher_number . ' ' . $b['role_label'] . ' (' . $b['detail'] . ')';
            }
        }

        return $lines ? 'This salary payment cannot be recorded yet. Missing signatures: ' . implode('; ', $lines) : null;
    }

    /** Rolls the period's own status up from its records' actual payment state — never trusts a single record's status alone. */
    private function syncPeriodStatus(SalaryPeriod $period): void
    {
        $records = $period->records()->get();
        $allPaid = $records->every(fn (SalaryRecord $r) => $r->remainingBalance() <= 0);
        $anyPaid = $records->contains(fn (SalaryRecord $r) => $r->totalPaid() > 0);

        // Falls back to Approved (not "leave unchanged") when nothing is
        // paid any more — unreachable before reversals existed (payments
        // only ever moved forward), but a full reversal can now bring
        // anyPaid back to false while the period is still sitting at Paid.
        $period->update(['status' => $allPaid ? SalaryPeriod::STATUS_PAID : ($anyPaid ? SalaryPeriod::STATUS_PARTIALLY_PAID : SalaryPeriod::STATUS_APPROVED)]);
    }

    private function notifyStaff(SalaryRecord $record, float $amountJustPaid): void
    {
        $remaining = $record->remainingBalance();
        $eventKey = $remaining <= 0 ? 'paid' : 'partially_paid';
        $staff = $record->staff;
        $record->loadMissing('period');

        // Salary workflow page (Who Gets Notified / Messages): the employee's own notice, plus anyone the page adds. Link-free SMS text.
        \App\Services\Workflow\WorkflowMessenger::dispatch('salary', $eventKey, $record, auth()->user(), [
            'account_id' => auth()->user()?->account_id ?? 1, 'owner_staff' => $staff, 'salary_record' => $record, 'amount' => $amountJustPaid, 'remaining' => $remaining,
            'title' => $remaining <= 0 ? __('Salary Paid') : __('Partial Salary Payment'),
            'message' => $remaining <= 0
                ? __('Your salary for :period has been paid in full.', ['period' => $record->period->label])
                : __('A partial salary payment for :period has been made. Remaining balance: :remaining.', ['period' => $record->period->label, 'remaining' => number_format($remaining, 2)]),
        ]);
    }
}
