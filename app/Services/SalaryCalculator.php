<?php

namespace App\Services;

use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceRepayment;
use App\Models\SalaryComponent;
use App\Models\SalaryLoan;
use App\Models\SalaryLoanRepayment;
use App\Models\SalaryOpeningBalance;
use App\Models\SalaryOutstandingAdjustment;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use App\Models\SalaryRecordItem;
use App\Models\SalaryStatutorySetting;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single place salary amounts are computed — individual and bulk
 * preparation both call prepareForStaff(), so there's exactly one
 * calculation path, matching the plan's "every calculated amount must have
 * a clear source" rule. Every component/advance/loan deduction becomes its
 * own SalaryRecordItem row, and advance/loan deductions are committed to
 * their repayment ledgers at preparation time (not payment time) so
 * preparing the same period twice can never double-charge the same
 * advance/loan — the (advance_id, record_id) / (loan_id, record_id) unique
 * constraints catch that.
 */
class SalaryCalculator
{
    /** @throws \RuntimeException if a record already exists for this staff/period */
    public function prepareForStaff(SalaryPeriod $period, Staff $staff, int $createdByUserId): SalaryRecord
    {
        return DB::transaction(function () use ($period, $staff, $createdByUserId) {
            if (SalaryRecord::where('salary_period_id', $period->id)->where('staff_id', $staff->id)->exists()) {
                throw new \RuntimeException("{$staff->name} already has a salary record for this period.");
            }

            $openingOutstanding = $this->openingOutstandingBalance($staff, $period);

            $record = SalaryRecord::create([
                'salary_period_id' => $period->id,
                'staff_id' => $staff->id,
                'basic_salary' => $staff->basic_salary,
                'opening_outstanding_balance' => $openingOutstanding,
                'status' => SalaryRecord::STATUS_DRAFT,
                'created_by_user_id' => $createdByUserId,
            ]);

            $earnings = 0;
            $deductions = 0;

            // Pro-rata for an employee who joined partway through this
            // period — only the join date is tracked (there's no separate
            // "left on" date distinct from Staff::status), so this only
            // covers the mid-period-joiner scenario, not mid-period leavers.
            $proRateFactor = $this->proRateFactor($staff, $period);
            $proRateSuffix = $proRateFactor < 1
                ? ' (Pro-rated: ' . round($proRateFactor * 100) . '%)'
                : '';

            $basicSalary = round((float) $staff->basic_salary * $proRateFactor, 2);
            $allowances = round((float) $staff->allowances * $proRateFactor, 2);

            if ($basicSalary > 0) {
                $earnings += $this->addItem($record, 'Basic Salary' . $proRateSuffix, $basicSalary, SalaryRecordItem::TYPE_EARNING, SalaryRecordItem::SOURCE_COMPONENT);
            }
            if ($allowances > 0) {
                $earnings += $this->addItem($record, 'Allowances' . $proRateSuffix, $allowances, SalaryRecordItem::TYPE_EARNING, SalaryRecordItem::SOURCE_COMPONENT);
            }
            if ((float) $staff->deductions > 0) {
                $deductions += $this->addItem($record, 'Other Deductions', $staff->deductions, SalaryRecordItem::TYPE_DEDUCTION, SalaryRecordItem::SOURCE_COMPONENT);
            }

            $accountId = User::find($createdByUserId)?->account_id ?? 1;
            $statutorySettings = SalaryStatutorySetting::forAccount($accountId);

            if ($statutorySettings->is_enabled) {
                $statutory = new SalaryStatutoryCalculator($statutorySettings);
                $grossPay = $earnings;

                $paye = $statutory->paye($grossPay);
                if ($paye > 0) {
                    $deductions += $this->addItem($record, 'PAYE (Income Tax)', $paye, SalaryRecordItem::TYPE_DEDUCTION, SalaryRecordItem::SOURCE_STATUTORY);
                }

                $nssfEmployee = $statutory->nssfEmployee($grossPay);
                if ($nssfEmployee > 0) {
                    $deductions += $this->addItem($record, 'ZSSF Contribution (Employee)', $nssfEmployee, SalaryRecordItem::TYPE_DEDUCTION, SalaryRecordItem::SOURCE_STATUTORY);
                }

                // Employer-only costs — tracked for reporting, never
                // deducted from the employee and never counted in
                // total_deductions/net_salary (see TYPE_EMPLOYER_COST).
                $nssfEmployer = $statutory->nssfEmployer($grossPay);
                if ($nssfEmployer > 0) {
                    $this->addItem($record, 'ZSSF Contribution (Employer)', $nssfEmployer, SalaryRecordItem::TYPE_EMPLOYER_COST, SalaryRecordItem::SOURCE_STATUTORY);
                }

                $wcf = $statutory->wcf($grossPay);
                if ($wcf > 0) {
                    $this->addItem($record, 'WCF Contribution', $wcf, SalaryRecordItem::TYPE_EMPLOYER_COST, SalaryRecordItem::SOURCE_STATUTORY);
                }

                $sdl = $statutory->sdl($grossPay);
                if ($sdl > 0) {
                    $this->addItem($record, 'SDL (Skills Development Levy)', $sdl, SalaryRecordItem::TYPE_EMPLOYER_COST, SalaryRecordItem::SOURCE_STATUTORY);
                }
            }

            // Advance/loan installments must never push net salary below
            // zero on their own — each one's nextDeductionAmount() only
            // caps against ITS OWN remaining balance, so two or more active
            // advances/loans together could otherwise exceed what the
            // employee actually earned this period. Capped to what's left
            // of earnings-after-flat-deductions as each is applied in turn;
            // whatever doesn't fit stays outstanding and is picked up again
            // next period (nextDeductionAmount() is re-evaluated fresh then).
            $availableForRepayments = max(0, $earnings - $deductions);

            $advanceDeduction = $this->applyActiveAdvances($record, $staff, $availableForRepayments);
            $availableForRepayments = max(0, $availableForRepayments - $advanceDeduction);

            $loanDeduction = $this->applyActiveLoans($record, $staff, $availableForRepayments);
            $deductions += $advanceDeduction + $loanDeduction;

            $netSalary = round($earnings - $deductions, 2);

            $record->update([
                'total_earnings' => round($earnings, 2),
                'total_deductions' => round($deductions, 2),
                'advance_deduction' => round($advanceDeduction, 2),
                'loan_deduction' => round($loanDeduction, 2),
                'net_salary' => $netSalary,
                'current_payable' => round($netSalary + $openingOutstanding, 2),
            ]);

            // First salary prepared after an Opening Balance entry picks up that employee's
            // brought-forward unpaid salary (no-op when there is none).
            $this->applyPendingOpeningBalance($record->fresh(), $createdByUserId);

            return $record->fresh('items');
        });
    }

    /**
     * Adds the employee's pending Salary Opening Balance (unpaid salary brought
     * forward from before WIMS) to this record's opening outstanding balance,
     * through the normal adjustment ledger, and marks the entry as applied.
     * Safe to call repeatedly: once applied to a live record it is skipped.
     */
    public function applyPendingOpeningBalance(SalaryRecord $record, int $userId): ?SalaryOpeningBalance
    {
        return DB::transaction(function () use ($record, $userId) {
            $pending = SalaryOpeningBalance::pendingOutstandingFor($record->staff_id)->lockForUpdate()->first();

            if (! $pending) {
                return null;
            }

            $reason = __('Opening balance brought forward (:ref): unpaid salary for :from to :to.', [
                'ref' => $pending->reference,
                'from' => $pending->balance_from->format('d M Y'),
                'to' => $pending->balance_to->format('d M Y'),
            ]);
            if ($pending->remarks) {
                $reason .= ' ' . $pending->remarks;
            }

            $this->recordAdjustment($record->fresh(), SalaryOutstandingAdjustment::TYPE_OPENING_BALANCE, (float) $pending->outstanding_salary, $reason, $userId);

            $pending->update([
                'outstanding_applied_record_id' => $record->id,
                'outstanding_applied_at' => now(),
            ]);

            return $pending;
        });
    }

    /**
     * 1.0 unless the staff member's join_date falls inside this period, in
     * which case it's the fraction of the period's calendar days they were
     * actually employed for. There's no tracked "left on" date distinct
     * from Staff::status, so a mid-period leaver isn't covered here.
     */
    private function proRateFactor(Staff $staff, SalaryPeriod $period): float
    {
        if (! $staff->join_date || $staff->join_date->lte($period->period_start) || $staff->join_date->gt($period->period_end)) {
            return 1.0;
        }

        $periodDays = $period->period_start->diffInDays($period->period_end) + 1;
        $workedDays = $staff->join_date->diffInDays($period->period_end) + 1;

        return $periodDays > 0 ? round($workedDays / $periodDays, 4) : 1.0;
    }

    private function addItem(SalaryRecord $record, string $label, $amount, string $type, string $source, ?int $componentId = null): float
    {
        SalaryRecordItem::create([
            'salary_record_id' => $record->id,
            'salary_component_id' => $componentId,
            'label' => $label,
            'amount' => $amount,
            'type' => $type,
            'source' => $source,
        ]);

        return (float) $amount;
    }

    /** The prior period's unpaid remaining balance for this staff member, carried forward — 0 if there's no prior period or it's fully settled. */
    private function openingOutstandingBalance(Staff $staff, SalaryPeriod $currentPeriod): float
    {
        $priorRecord = SalaryRecord::where('staff_id', $staff->id)
            ->where('status', '!=', SalaryRecord::STATUS_REJECTED)
            ->whereHas('period', fn ($q) => $q->where('period_start', '<', $currentPeriod->period_start))
            ->with('period')
            ->get()
            ->sortByDesc(fn (SalaryRecord $r) => $r->period->period_start)
            ->first();

        return $priorRecord ? max(0, $priorRecord->remainingBalance()) : 0.0;
    }

    private function applyActiveAdvances(SalaryRecord $record, Staff $staff, float $availableRoom): float
    {
        $total = 0;

        $advances = SalaryAdvance::where('staff_id', $staff->id)->where('status', SalaryAdvance::STATUS_ACTIVE)->get();

        foreach ($advances as $advance) {
            $amount = min($advance->nextDeductionAmount(), $availableRoom - $total);
            if ($amount <= 0) {
                continue;
            }

            SalaryAdvanceRepayment::create([
                'salary_advance_id' => $advance->id,
                'salary_record_id' => $record->id,
                'amount' => $amount,
            ]);

            $this->addItem($record, 'Advance Repayment (' . $advance->reference . ')', $amount, SalaryRecordItem::TYPE_DEDUCTION, SalaryRecordItem::SOURCE_ADVANCE);
            $total += $amount;

            if ($advance->outstandingBalance() <= 0) {
                $advance->update(['status' => SalaryAdvance::STATUS_COMPLETED]);
            }
        }

        return $total;
    }

    private function applyActiveLoans(SalaryRecord $record, Staff $staff, float $availableRoom): float
    {
        $total = 0;

        $loans = SalaryLoan::where('staff_id', $staff->id)->where('status', SalaryLoan::STATUS_ACTIVE)->get();

        foreach ($loans as $loan) {
            $amount = min($loan->nextDeductionAmount(), $availableRoom - $total);
            if ($amount <= 0) {
                continue;
            }

            SalaryLoanRepayment::create([
                'salary_loan_id' => $loan->id,
                'salary_record_id' => $record->id,
                'principal_portion' => $amount,
                'interest_portion' => 0,
                'amount' => $amount,
            ]);

            $this->addItem($record, 'Loan Repayment (' . $loan->reference . ')', $amount, SalaryRecordItem::TYPE_DEDUCTION, SalaryRecordItem::SOURCE_LOAN);
            $total += $amount;

            if ($loan->outstandingPrincipal() <= 0) {
                $loan->update(['status' => SalaryLoan::STATUS_COMPLETED]);
            }
        }

        return $total;
    }

    /** Recorded as its own immutable audit row; the record's opening_outstanding_balance is recalculated from the sum of all its adjustments plus the original carried-forward amount. */
    public function recordAdjustment(SalaryRecord $record, string $type, float $amount, string $reason, int $userId): SalaryOutstandingAdjustment
    {
        return DB::transaction(function () use ($record, $type, $amount, $reason, $userId) {
            $adjustment = SalaryOutstandingAdjustment::create([
                'salary_record_id' => $record->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
                'created_by_user_id' => $userId,
            ]);

            $newOpening = round((float) $record->opening_outstanding_balance + $amount, 2);
            $record->update([
                'opening_outstanding_balance' => $newOpening,
                'current_payable' => round((float) $record->net_salary + $newOpening, 2),
            ]);

            return $adjustment;
        });
    }

    /** A one-off earning/deduction line (overtime, bonus, leave-without-pay, etc.) on a Draft record — recalculates its totals from every item afterward, never from a stored running total, so it can never drift. */
    public function addManualItem(SalaryRecord $record, string $label, float $amount, string $type): SalaryRecordItem
    {
        abort_unless($record->status === SalaryRecord::STATUS_DRAFT, 422, 'Only a Draft salary record can have items added.');

        return DB::transaction(function () use ($record, $label, $amount, $type) {
            $item = SalaryRecordItem::create([
                'salary_record_id' => $record->id,
                'label' => $label,
                'amount' => $amount,
                'type' => $type,
                'source' => SalaryRecordItem::SOURCE_MANUAL,
            ]);

            $this->recalculateTotals($record);

            return $item;
        });
    }

    public function removeManualItem(SalaryRecordItem $item): void
    {
        $record = $item->record;

        abort_unless($record->status === SalaryRecord::STATUS_DRAFT, 422, 'Only a Draft salary record can have items removed.');
        abort_unless($item->source === SalaryRecordItem::SOURCE_MANUAL, 422, 'Only a manually-added line can be removed this way.');

        DB::transaction(function () use ($item, $record) {
            $item->delete();
            $this->recalculateTotals($record);
        });
    }

    private function recalculateTotals(SalaryRecord $record): void
    {
        $record->load('items');

        $earnings = (float) $record->items->where('type', SalaryRecordItem::TYPE_EARNING)->sum('amount');
        $deductions = (float) $record->items->where('type', SalaryRecordItem::TYPE_DEDUCTION)->sum('amount');
        $netSalary = round($earnings - $deductions, 2);

        $record->update([
            'total_earnings' => round($earnings, 2),
            'total_deductions' => round($deductions, 2),
            'net_salary' => $netSalary,
            'current_payable' => round($netSalary + (float) $record->opening_outstanding_balance, 2),
        ]);
    }
}
