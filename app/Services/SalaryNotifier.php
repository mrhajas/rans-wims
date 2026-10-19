<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Salary notices beyond the period steps and the employee's payment notice: periods created / deleted / cancelled, records changed,
 * advances, loans, opening balances, reversed and bulk payments, vouchers and settings. Who is told, on which channels and with which
 * words is set on the Salary workflow page. The employee (a Staff record) is "the owner" of an advance / loan notice.
 */
class SalaryNotifier
{
    /**
     * @param array $v extra #tags: reference, period, batch, status, employee, amount, remaining
     */
    public static function fire(string $event, ?Model $record, ?User $actor, string $title, string $message, array $v = [], ?Staff $staff = null, array $ctx = []): bool
    {
        $actor ??= auth()->user();
        $staff ??= self::staffOf($record);

        return WorkflowEvent::fire('salary', $event, $record, $actor, $title, $message, $ctx + array_filter([
            'owner_staff' => $staff,
            'url' => self::url($record),
            'details' => array_filter([__('Reference') => $v['reference'] ?? null, __('Employee') => $staff?->name, __('Period') => $v['period'] ?? null, __('Done by') => $actor?->name], fn ($x) => filled($x)),
            'values' => ['reference' => '', 'period' => '', 'batch' => '', 'status' => (string) ($record->status ?? ''), 'employee' => (string) ($staff?->name ?? ''), 'amount' => '', 'remaining' => ''] + $v,
        ], fn ($x) => $x !== null));
    }

    private static function staffOf(?Model $record): ?Staff
    {
        if (! $record) {
            return null;
        }
        $id = $record->staff_id ?? null;

        return $id ? Staff::find($id) : null;
    }

    private static function url(?Model $record): ?string
    {
        try {
            return match (true) {
                $record instanceof \App\Models\SalaryAdvance => route('salary.advances.show', $record->id),
                $record instanceof \App\Models\SalaryLoan => route('salary.loans.show', $record->id),
                $record instanceof \App\Models\SalaryOpeningBalance => route('salary.opening-balances.index'),
                $record instanceof \App\Models\SalaryPeriod => route('salary.periods.show', $record->id),
                $record instanceof \App\Models\SalaryRecord => route('salary.periods.show', $record->salary_period_id),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    public static function money($n): string
    {
        return 'TZS ' . number_format((float) $n, 0);
    }
}
