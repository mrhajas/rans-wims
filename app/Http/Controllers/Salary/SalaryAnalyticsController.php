<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Models\SalaryLoan;
use App\Models\SalaryPayment;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use App\Models\SalaryRecordItem;
use App\Models\Staff;
use Illuminate\Http\Request;
use App\Services\SalaryBalanceSummary;
use Inertia\Inertia;

class SalaryAnalyticsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-salary-analytics');

        return Inertia::render('Salary/Analytics', $this->computeStats());
    }

    /**
     * One shared payload builder — covers the spec's deep-analytics metrics
     * that are actually derivable from Pass 1 data: payroll obligation,
     * paid/outstanding, average salary, cost trend, outstanding ageing,
     * advance/loan balances, deductions by type/source, and employees with
     * an outstanding balance or an active advance/loan.
     */
    private function computeStats(): array
    {
        $records = SalaryRecord::with('period:id,label,period_start')->withSum('payments', 'amount')->get();
        // Not sum(current_payable): that double counts carried-forward arrears (see SalaryBalanceSummary).
        $totals = SalaryBalanceSummary::totals();
        $totalPayroll = $totals['payable'];
        $totalPaid = $totals['paid'];
        $totalOutstanding = $totals['outstanding'];
        $averageSalary = $records->count() ? round((float) $records->avg('net_salary'), 2) : 0;

        $trend = SalaryPeriod::orderByDesc('period_start')->limit(12)->get()
            ->sortBy('period_start')
            ->map(fn (SalaryPeriod $p) => ['label' => $p->label, 'payable' => $p->totalPayable(), 'paid' => $p->totalPaid()])
            ->values();

        $deductionsBySource = SalaryRecordItem::query()
            ->where('type', 'deduction')
            ->selectRaw('source, sum(amount) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $ageingBuckets = SalaryBalanceSummary::ageingBuckets();

        $activeAdvances = SalaryAdvance::where('status', SalaryAdvance::STATUS_ACTIVE)->with('staff:id,name')->get();
        $activeLoans = SalaryLoan::where('status', SalaryLoan::STATUS_ACTIVE)->with('staff:id,name')->get();

        $employeesWithOutstanding = SalaryBalanceSummary::outstandingRows()
            ->map(fn (SalaryRecord $r) => ['staff_name' => optional($r->staff)->name, 'period_label' => optional($r->period)->label, 'amount' => $r->remainingBalance()])
            ->take(10)
            ->values();

        return [
            'summary' => [
                'total_payroll_obligation' => $totalPayroll,
                'total_paid' => round($totalPaid, 2),
                'total_outstanding' => $totalOutstanding,
                'average_salary' => $averageSalary,
                'total_employees_paid' => $records->count(),
                'total_advances_outstanding' => round($activeAdvances->sum(fn ($a) => $a->outstandingBalance()), 2),
                'total_loans_outstanding' => round($activeLoans->sum(fn ($l) => $l->outstandingPrincipal()), 2),
                'employees_with_active_advances' => $activeAdvances->count(),
                'employees_with_active_loans' => $activeLoans->count(),
            ],
            'trend' => $trend,
            'deductionsBySource' => $deductionsBySource,
            'ageingBuckets' => $ageingBuckets,
            'employeesWithOutstanding' => $employeesWithOutstanding,
            'activeAdvances' => $activeAdvances->map(fn (SalaryAdvance $a) => [
                'reference' => $a->reference, 'staff_name' => optional($a->staff)->name,
                'amount' => (float) $a->amount, 'outstanding' => $a->outstandingBalance(),
            ])->values(),
            'activeLoans' => $activeLoans->map(fn (SalaryLoan $l) => [
                'reference' => $l->reference, 'staff_name' => optional($l->staff)->name,
                'amount' => (float) $l->principal_amount, 'outstanding' => $l->outstandingPrincipal(),
            ])->values(),
        ];
    }
}
