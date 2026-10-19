<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * What an employee already owes / is owed when the company starts using WIMS:
 * unpaid salary, a loan still being repaid and/or an advance still being
 * recovered, over a stated span (balance_from – balance_to).
 *
 * The loan and advance parts are materialised as real, Active SalaryLoan /
 * SalaryAdvance rows (linked by salary_loan_id / salary_advance_id) so the
 * normal payroll engine deducts them from the next salary prepared. The
 * unpaid-salary part is added to the employee's next prepared salary record
 * as an `opening_balance` outstanding adjustment (see
 * SalaryCalculator::applyPendingOpeningBalance) and then stays on the ledger
 * like any other carried-forward balance.
 */
class SalaryOpeningBalance extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    protected $fillable = [
        'reference', 'staff_id', 'balance_from', 'balance_to',
        'outstanding_salary', 'outstanding_applied_record_id', 'outstanding_applied_at',
        'loan_amount', 'loan_installments', 'salary_loan_id',
        'advance_amount', 'advance_installments', 'salary_advance_id',
        'remarks', 'created_by_user_id', 'updated_by_user_id', 'extra_attributes',
    ];

    public $casts = [
        'extra_attributes' => 'array',
        'balance_from' => 'date:Y-m-d',
        'balance_to' => 'date:Y-m-d',
        'outstanding_salary' => 'decimal:2',
        'loan_amount' => 'decimal:2',
        'advance_amount' => 'decimal:2',
        'outstanding_applied_at' => 'datetime',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function loan()
    {
        return $this->belongsTo(SalaryLoan::class, 'salary_loan_id');
    }

    public function advance()
    {
        return $this->belongsTo(SalaryAdvance::class, 'salary_advance_id');
    }

    public function appliedRecord()
    {
        return $this->belongsTo(SalaryRecord::class, 'outstanding_applied_record_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Unpaid-salary amounts not (or no longer) sitting on a live salary record.
     * "No longer" covers a record that was deleted or whose period was
     * rejected/cancelled — the amount then goes back to being pending and is
     * picked up again by the next salary prepared for that employee.
     */
    public function scopePendingOutstandingFor(Builder $query, int $staffId): Builder
    {
        return $query->where('staff_id', $staffId)
            ->where('outstanding_salary', '>', 0)
            ->where(function (Builder $q) {
                $q->whereNull('outstanding_applied_record_id')
                    ->orWhereNotExists(function ($sub) {
                        $sub->selectRaw('1')->from('salary_records')
                            ->whereColumn('salary_records.id', 'salary_opening_balances.outstanding_applied_record_id')
                            ->whereNull('salary_records.deleted_at')
                            ->where('salary_records.status', '!=', SalaryRecord::STATUS_REJECTED);
                    });
            });
    }

    public function hasOutstanding(): bool
    {
        return (float) $this->outstanding_salary > 0;
    }

    public function outstandingApplied(): bool
    {
        if (! $this->hasOutstanding() || ! $this->outstanding_applied_record_id) {
            return false;
        }

        $record = $this->appliedRecord;

        return $record !== null && $record->status !== SalaryRecord::STATUS_REJECTED;
    }

    /** True once any part has been used by payroll — from then on it is corrected via adjustments, not by editing this entry. */
    public function isLocked(): bool
    {
        if ($this->outstandingApplied()) {
            return true;
        }

        if ($this->loan && $this->loan->repayments()->exists()) {
            return true;
        }

        if ($this->advance && $this->advance->repayments()->exists()) {
            return true;
        }

        return false;
    }
}
