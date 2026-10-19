<?php

namespace App\Services;

use App\Models\SalaryPayment;
use App\Models\SalaryRecord;
use Illuminate\Support\Collection;

/**
 * Company-wide salary balances, computed the one way that cannot double count.
 *
 * Every SalaryRecord's current_payable already contains the arrears carried
 * forward from the employee's previous record (opening_outstanding_balance), so
 * summing current_payable or per-record balances across periods counts the same
 * unpaid money once per month. What an employee really still owes is simply the
 * remaining balance of their LATEST live record; the company-wide figure is the
 * sum of those. (Equivalent to: all net salaries + all adjustments / opening
 * balances − all payments.)
 *
 * Rejected/cancelled-period records (status Rejected) and deleted records are
 * excluded, exactly as SalaryCalculator::openingOutstandingBalance() does.
 */
class SalaryBalanceSummary
{
    /** @return Collection<int, SalaryRecord> each employee's latest live record, keyed by staff_id */
    public static function latestRecords(): Collection
    {
        return self::liveRecords()
            ->groupBy('staff_id')
            ->map(fn (Collection $g) => $g->sortByDesc(fn (SalaryRecord $r) => self::orderKey($r))->first());
    }

    /** Company totals: booked = everything ever owed (net + adjustments), paid, outstanding = still owed. */
    public static function totals(): array
    {
        $outstanding = round(self::latestRecords()->sum(fn (SalaryRecord $r) => max(0, $r->remainingBalance())), 2);
        $paid = round((float) SalaryPayment::whereIn('salary_record_id', self::liveRecords()->pluck('id'))->sum('amount'), 2);

        return [
            'payable' => round($outstanding + $paid, 2),
            'paid' => $paid,
            'outstanding' => $outstanding,
        ];
    }

    /** One row per employee who still owes salary, largest first — the record carries the full running balance. */
    public static function outstandingRows(): Collection
    {
        return self::latestRecords()
            ->filter(fn (SalaryRecord $r) => $r->remainingBalance() > 0)
            ->sortByDesc(fn (SalaryRecord $r) => $r->remainingBalance())
            ->values();
    }

    /**
     * Ageing of what is still owed. Payments settle the oldest money first (FIFO), so each employee's
     * outstanding amount is attributed to their NEWEST bookings: walk records newest → oldest, taking
     * as much of each record's own booking (net salary + its adjustments) as is still unaccounted for.
     *
     * @return array<string,float>
     */
    public static function ageingBuckets(): array
    {
        $buckets = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];

        foreach (self::liveRecords()->groupBy('staff_id') as $records) {
            $sorted = $records->sortByDesc(fn (SalaryRecord $r) => self::orderKey($r))->values();
            $left = max(0, $sorted->first()->remainingBalance());

            foreach ($sorted as $i => $record) {
                if ($left <= 0) {
                    break;
                }

                $booked = (float) $record->net_salary + (float) $record->adjustments->sum('amount');
                $take = $i === $sorted->count() - 1 ? $left : min($left, max(0, $booked));
                if ($take <= 0) {
                    continue;
                }

                $days = now()->diffInDays($record->period->period_end, false) * -1;
                $key = $days <= 30 ? '0-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+'));
                $buckets[$key] += $take;
                $left -= $take;
            }
        }

        return array_map(fn ($v) => round($v, 2), $buckets);
    }

    private static function liveRecords(): Collection
    {
        return SalaryRecord::with(['staff:id,name,staff_number', 'period:id,label,period_start,period_end', 'adjustments'])
            ->withSum('payments', 'amount')
            ->where('status', '!=', SalaryRecord::STATUS_REJECTED)
            ->get()
            ->filter(fn (SalaryRecord $r) => $r->period !== null)
            ->values();
    }

    private static function orderKey(SalaryRecord $r): string
    {
        return $r->period->period_start->format('Ymd') . str_pad((string) $r->id, 10, '0', STR_PAD_LEFT);
    }
}
