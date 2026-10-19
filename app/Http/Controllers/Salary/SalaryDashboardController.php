<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Models\SalaryLoan;
use App\Models\SalaryPayment;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use Illuminate\Http\Request;
use App\Services\SalaryBalanceSummary;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class SalaryDashboardController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-salary-analytics');

        return Inertia::render('Salary/Dashboard', $this->computeStats());
    }

    private function computeStats(): array
    {
        // Cross-period totals must not add up current_payable: it already includes carried-forward
        // arrears, so that would count the same unpaid salary once per month (see SalaryBalanceSummary).
        $totals = SalaryBalanceSummary::totals();
        $totalPayable = $totals['payable'];
        $totalPaid = $totals['paid'];

        $currentPeriod = SalaryPeriod::orderByDesc('period_start')->first();

        return [
            'kpis' => [
                'total_payable' => round($totalPayable, 2),
                'total_paid' => round($totalPaid, 2),
                'total_outstanding' => $totals['outstanding'],
                'current_period_label' => optional($currentPeriod)->label,
                'current_period_payable' => $currentPeriod ? $currentPeriod->totalPayable() : 0,
                'pending_approval' => SalaryPeriod::where('status', SalaryPeriod::STATUS_PENDING_APPROVAL)->count(),
                'approved_awaiting_payment' => SalaryPeriod::where('status', SalaryPeriod::STATUS_APPROVED)->count(),
                'paid_periods' => SalaryPeriod::where('status', SalaryPeriod::STATUS_PAID)->count(),
                'partially_paid_periods' => SalaryPeriod::where('status', SalaryPeriod::STATUS_PARTIALLY_PAID)->count(),
                'total_advances_outstanding' => round(SalaryAdvance::where('status', SalaryAdvance::STATUS_ACTIVE)->get()->sum(fn ($a) => $a->outstandingBalance()), 2),
                'total_loans_outstanding' => round(SalaryLoan::where('status', SalaryLoan::STATUS_ACTIVE)->get()->sum(fn ($l) => $l->outstandingPrincipal()), 2),
            ],
            'byStatus' => SalaryPeriod::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'trend' => $this->trend(),
            'activity' => $this->recentActivity(),
        ];
    }

    /** Last 6 salary periods (oldest first) — payable vs paid, for the trend chart. */
    private function trend(): array
    {
        return SalaryPeriod::orderByDesc('period_start')
            ->limit(6)
            ->get()
            ->sortBy('period_start')
            ->map(fn (SalaryPeriod $p) => [
                'label' => $p->label,
                'payable' => $p->totalPayable(),
                'paid' => $p->totalPaid(),
            ])
            ->values()
            ->all();
    }

    private function recentActivity(): array
    {
        return Activity::query()
            ->whereIn('log_name', ['Salary', 'Salary Advance', 'Salary Loan'])
            ->with('causer:id,name')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (Activity $a) => [
                'description' => $a->description,
                'causer_name' => optional($a->causer)->name ?? __('System'),
                'created_at' => $a->created_at->diffForHumans(),
            ])
            ->values()
            ->all();
    }
}
