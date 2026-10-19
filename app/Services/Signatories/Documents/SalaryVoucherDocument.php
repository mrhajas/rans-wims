<?php

namespace App\Services\Signatories\Documents;

use App\Models\SalaryPayment;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use App\Models\SalaryVoucher;
use Illuminate\Support\Carbon;

/**
 * Salary Voucher (individual vouchers and the bulk master voucher). A salary voucher has NO status column, so the workflow facts come from
 * three places:
 *
 *  - prepared  salary_vouchers.prepared_by_user_id + created_at (the person who generated the voucher). GOOD.
 *  - approved  The voucher-level approved_by_user_id is never written by any code path (always null), so it is NOT used. The real approval is
 *              the one of the salary PERIOD the voucher belongs to: salary_periods.approved_by_user_id + approved_at, written by
 *              SalaryPeriodController::approve(). Reported only while the period status is Approved, Partially Paid, Paid or Closed; a
 *              Rejected or Cancelled period (approval void) or one still Draft/Preparing/Pending Approval shows no approval. GOOD.
 *  - paid      From the immutable salary_payments ledger of the voucher's salary record(s), not from salary_vouchers.paid_by_user_id (which is
 *              only written when a payment settles the record and is not reliable for a bulk master, and salary_payments.salary_voucher_id is
 *              null for payments made before the voucher was generated). A payment that was reversed (a negative row with reverses_payment_id)
 *              and the reversal itself are ignored. "Paid" means the record is fully settled (current_payable minus the net of all payments is
 *              zero or less); then the person and time (created_at; payment_date is a typed date without time) of the payment that brought the
 *              running total of the standing payments up to the amount payable are used. A voucher that is only partly paid, or whose payments
 *              were all reversed, has no "paid" action. GOOD when one person made the standing payments; PARTIAL when several people did (the
 *              person who made the settling payment is named).
 *              Bulk master voucher: "paid" only when every salary record of the period is settled; the settling payment of the record settled
 *              last is used; PARTIAL when different people settled different records.
 *
 * State (documented, no status column): the period status Draft/Preparing = draft; "paid" exists, or the period is Closed = final;
 * everything else (Pending Approval, Approved, Partially Paid, Rejected, Cancelled, Paid-with-no-payment-rows) = in progress. A payment
 * reversal after the voucher was final moves it back to in progress, but a snapshot already frozen stays frozen.
 */
final class SalaryVoucherDocument implements SignableDocument
{
    private const APPROVED_STANDING = [SalaryPeriod::STATUS_APPROVED, SalaryPeriod::STATUS_PARTIALLY_PAID, SalaryPeriod::STATUS_PAID, SalaryPeriod::STATUS_CLOSED];

    public static function key(): string
    {
        return 'salary_voucher';
    }

    public static function label(): string
    {
        return 'Salary Voucher';
    }

    public static function actions(): array
    {
        return ['prepared' => 'Prepared (voucher generated)', 'approved' => 'Approved (salary period approval)', 'paid' => 'Paid (voucher settled)'];
    }

    public function find(int $id): ?object
    {
        return SalaryVoucher::query()->find($id);
    }

    private function period(object $record): ?SalaryPeriod
    {
        return $record->salary_period_id ? SalaryPeriod::query()->withoutGlobalScope('workflow_visibility')->find($record->salary_period_id) : null;
    }

    public function state(object $record): string
    {
        $period = $this->period($record);
        if (! $period || in_array($period->status, [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING], true)) {
            return 'draft';
        }
        if ($period->status === SalaryPeriod::STATUS_CLOSED || $this->paid($record, $period) !== null) {
            return 'final';
        }

        return 'in_progress';
    }

    public function history(object $record): array
    {
        $out = [];
        $out['prepared'] = $this->entry($record->prepared_by_user_id, $record->created_at, 'The person who generated the voucher is not recorded.');

        $period = $this->period($record);
        if ($period && in_array($period->status, self::APPROVED_STANDING, true)) {
            $out['approved'] = $this->entry($period->approved_by_user_id, $period->approved_at, 'The approval of the salary period is not fully recorded.');
        }
        if ($period && ($paid = $this->paid($record, $period)) !== null) {
            $out['paid'] = $paid;
        }

        return $out;
    }

    /** The "paid" row, or null when the voucher is not (or no longer) fully settled. */
    private function paid(object $record, SalaryPeriod $period): ?array
    {
        if (! in_array($period->status, [SalaryPeriod::STATUS_APPROVED, SalaryPeriod::STATUS_PARTIALLY_PAID, SalaryPeriod::STATUS_PAID, SalaryPeriod::STATUS_CLOSED], true)) {
            return null; // payments only count on an approved period (a cancelled or rejected period's payments are not a standing payment)
        }
        if ($record->type === SalaryVoucher::TYPE_BULK) {
            $records = SalaryRecord::query()->where('salary_period_id', $period->id)->get();
            $settled = [];
            foreach ($records as $r) {
                $s = $this->settlement($r);
                if ($s === false) {
                    return null; // a record is not settled yet
                }
                if ($s) {
                    $settled[] = $s;
                }
            }
            if (! $settled) {
                return null;
            }
            usort($settled, fn ($a, $b) => [$a['pay']->created_at, $a['pay']->id] <=> [$b['pay']->created_at, $b['pay']->id]);
            $last = end($settled);
            $payers = collect($settled)->flatMap(fn ($s) => $s['payers'])->unique();
            $e = $this->entry($last['pay']->paid_by_user_id, $last['pay']->created_at, 'The payment is not fully recorded.');
            if ($payers->count() > 1) {
                $e['quality'] = 'partial';
                $e['note'] = 'Different people paid different salary records of this bulk voucher; the person who made the last settling payment is named.';
            }

            return $e;
        }
        $r = SalaryRecord::query()->where('salary_period_id', $period->id)->where('staff_id', $record->staff_id)->first();
        $s = $r ? $this->settlement($r) : false;
        if (! $s) {
            return null;
        }
        $e = $this->entry($s['pay']->paid_by_user_id, $s['pay']->created_at, 'The payment is not fully recorded.');
        if ($s['payers']->count() > 1) {
            $e['quality'] = 'partial';
            $e['note'] = 'The salary was paid in several payments by different people; the person who made the settling payment is named.';
        }

        return $e;
    }

    /**
     * @return array{pay: SalaryPayment, payers: \Illuminate\Support\Collection}|false|null  the settling payment of a fully settled record,
     *         false when the record is not settled, null when it is settled without any standing payment (nothing was payable)
     */
    private function settlement(SalaryRecord $r): array|false|null
    {
        $all = SalaryPayment::query()->where('salary_record_id', $r->id)->orderBy('id')->get();
        $net = round((float) $all->sum('amount'), 2);
        if (round((float) $r->current_payable - $net, 2) > 0) {
            return false;
        }
        $reversed = $all->whereNotNull('reverses_payment_id')->pluck('reverses_payment_id')->all();
        $standing = $all->filter(fn ($p) => $p->reverses_payment_id === null && (float) $p->amount > 0 && ! in_array($p->id, $reversed, true))->values();
        if ($standing->isEmpty()) {
            return null;
        }
        $running = 0.0;
        $settling = $standing->last();
        foreach ($standing as $p) {
            $running += (float) $p->amount;
            if (round((float) $r->current_payable - $running, 2) <= 0) {
                $settling = $p;
                break;
            }
        }

        return ['pay' => $settling, 'payers' => $standing->pluck('paid_by_user_id')->filter()->unique()->values()];
    }

    private function entry($userId, $at, string $note): array
    {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : null);
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    public static function defaultMappings(): array
    {
        // Printed labels: Prepared By, Approved By, Paid By. The Paid By line is covered by the block now, the view then hides its old standalone line.
        return [
            ['role' => 'prepared_by', 'action' => 'prepared', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'paid_by', 'action' => 'paid', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->voucher_number ?? ('#' . $record->id));
    }
}
