<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Models\SalaryLoan;
use App\Models\SalaryPayment;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use App\Models\Staff;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalaryReportController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    // --- Salary Register: every employee's line for one period ---

    public function register(Request $request)
    {
        $this->authorize('read-salary-reports');

        return Inertia::render('Salary/Reports/Register', [
            'periods' => SalaryPeriod::orderByDesc('period_start')->get(['id', 'label', 'batch_reference']),
            'payload' => $request->filled('salary_period_id') ? $this->registerPayload($request) : null,
        ]);
    }

    public function registerPrint(Request $request)
    {
        $this->authorize('export-salary-reports');

        return view('salary.register-print', $this->registerPayload($request) + ['isPdf' => false]);
    }

    public function registerPdf(Request $request)
    {
        $this->authorize('export-salary-reports');

        return Pdf::loadView('salary.register-print', $this->registerPayload($request) + ['isPdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('salary-register-' . now()->format('Ymd-His') . '.pdf');
    }

    private function registerPayload(Request $request): array
    {
        $period = SalaryPeriod::findOrFail($request->input('salary_period_id'));
        $records = $period->records()->with('staff:id,name,staff_number,position')->withSum('payments', 'amount')->get();

        return [
            'title' => __('Salary Register'),
            'period' => ['label' => $period->label, 'batch_reference' => $period->batch_reference],
            'rows' => $records->map(fn (SalaryRecord $r) => [
                'staff_name' => optional($r->staff)->name,
                'staff_number' => optional($r->staff)->staff_number,
                'basic_salary' => (float) $r->basic_salary,
                'total_earnings' => (float) $r->total_earnings,
                'total_deductions' => (float) $r->total_deductions,
                'net_salary' => (float) $r->net_salary,
                'current_payable' => (float) $r->current_payable,
                'total_paid' => $r->totalPaid(),
                'remaining_balance' => $r->remainingBalance(),
            ])->values(),
            'totals' => [
                'total_earnings' => round($records->sum('total_earnings'), 2),
                'total_deductions' => round($records->sum('total_deductions'), 2),
                'net_salary' => round($records->sum('net_salary'), 2),
                'total_payable' => round($records->sum('current_payable'), 2),
                'total_paid' => round($records->sum(fn (SalaryRecord $r) => $r->totalPaid()), 2),
            ],
            'generatedBy' => optional(auth()->user())->name,
            'generatedAt' => now(),
        ];
    }

    // --- Employee Salary Ledger: full transaction history for one employee ---

    public function ledger(Request $request, $staffId)
    {
        $this->authorize('view-salary-ledger');

        return Inertia::render('Salary/Reports/Ledger', $this->ledgerPayload($staffId));
    }

    public function ledgerPrint(Request $request, $staffId)
    {
        $this->authorize('export-salary-reports');

        return view('salary.ledger-print', $this->ledgerPayload($staffId) + ['isPdf' => false]);
    }

    public function ledgerPdf(Request $request, $staffId)
    {
        $this->authorize('export-salary-reports');

        return Pdf::loadView('salary.ledger-print', $this->ledgerPayload($staffId) + ['isPdf' => true])
            ->setPaper('a4')
            ->download('salary-ledger-' . now()->format('Ymd-His') . '.pdf');
    }

    private function ledgerPayload($staffId): array
    {
        $staff = Staff::findOrFail($staffId);
        $records = SalaryRecord::where('staff_id', $staffId)->with(['period:id,label,period_start', 'payments', 'adjustments'])->get()
            ->sortBy(fn (SalaryRecord $r) => $r->period->period_start);

        $entries = [];
        $running = 0;

        foreach ($records as $record) {
            $running += (float) $record->net_salary;
            $entries[] = [
                'date' => optional($record->period->period_start)->format('Y-m-d'),
                'reference' => $record->period->label,
                'description' => __('Salary for :period', ['period' => $record->period->label]),
                'debit' => 0,
                'credit' => (float) $record->net_salary,
                'balance' => round($running, 2),
                'type' => 'salary',
            ];

            foreach ($record->adjustments as $adj) {
                $running += (float) $adj->amount;
                $entries[] = [
                    'date' => optional($adj->created_at)->format('Y-m-d'),
                    'reference' => $record->period->label,
                    'description' => ($adj->type === 'opening_balance' ? __('Opening balance') : ucfirst($adj->type)) . ': ' . $adj->reason,
                    'debit' => $adj->amount > 0 ? (float) $adj->amount : 0,
                    'credit' => $adj->amount < 0 ? (float) abs($adj->amount) : 0,
                    'balance' => round($running, 2),
                    'type' => 'adjustment',
                ];
            }

            foreach ($record->payments as $payment) {
                $running -= (float) $payment->amount;
                $entries[] = [
                    'date' => optional($payment->payment_date)->format('Y-m-d'),
                    'reference' => $payment->payment_reference,
                    'description' => __('Payment for :period', ['period' => $record->period->label]),
                    'debit' => (float) $payment->amount,
                    'credit' => 0,
                    'balance' => round($running, 2),
                    'type' => 'payment',
                ];
            }
        }

        return [
            'title' => __('Employee Salary Ledger'),
            'staff' => ['id' => $staff->id, 'name' => $staff->name, 'staff_number' => $staff->staff_number, 'position' => $staff->position],
            'entries' => $entries,
            'closing_balance' => round($running, 2),
            'generatedBy' => optional(auth()->user())->name,
            'generatedAt' => now(),
        ];
    }

    // --- Individual Employee Salary Statement (current snapshot) ---

    public function statement(Request $request, $staffId)
    {
        $this->authorize('read-salary-reports');

        $staff = Staff::findOrFail($staffId);
        $records = SalaryRecord::where('staff_id', $staffId)->with('period:id,label,period_start')->withSum('payments', 'amount')->get()
            ->sortByDesc(fn (SalaryRecord $r) => $r->period->period_start)
            ->values();

        return Inertia::render('Salary/Reports/Statement', [
            'staff' => ['id' => $staff->id, 'name' => $staff->name, 'staff_number' => $staff->staff_number, 'position' => $staff->position, 'basic_salary' => (float) $staff->basic_salary],
            'history' => $records->map(fn (SalaryRecord $r) => [
                'period_label' => $r->period->label,
                'net_salary' => (float) $r->net_salary,
                'current_payable' => (float) $r->current_payable,
                'total_paid' => $r->totalPaid(),
                'remaining_balance' => $r->remainingBalance(),
                'status' => $r->status,
            ])->values(),
        ]);
    }

    public function statementPrint(Request $request, $staffId)
    {
        $this->authorize('export-salary-reports');

        return view('salary.list-report-print', $this->statementListPrintPayload($staffId) + ['isPdf' => false]);
    }

    public function statementPdf(Request $request, $staffId)
    {
        $this->authorize('export-salary-reports');

        return Pdf::loadView('salary.list-report-print', $this->statementListPrintPayload($staffId) + ['isPdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('salary-statement-' . now()->format('Ymd-His') . '.pdf');
    }

    private function statementListPrintPayload($staffId): array
    {
        $staff = Staff::findOrFail($staffId);
        $records = SalaryRecord::where('staff_id', $staffId)->with('period:id,label,period_start')->withSum('payments', 'amount')->get()
            ->sortByDesc(fn (SalaryRecord $r) => $r->period->period_start)
            ->values();

        return [
            'title' => __('Salary Statement') . ' - ' . $staff->name,
            'generatedBy' => optional(auth()->user())->name,
            'columns' => [
                ['key' => 'period_label', 'label' => 'Period'],
                ['key' => 'net_salary', 'label' => 'Net Salary', 'align' => 'right'],
                ['key' => 'current_payable', 'label' => 'Payable', 'align' => 'right'],
                ['key' => 'total_paid', 'label' => 'Paid', 'align' => 'right'],
                ['key' => 'remaining_balance', 'label' => 'Remaining', 'align' => 'right'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $records->map(fn (SalaryRecord $r) => [
                'period_label' => $r->period->label,
                'net_salary' => (float) $r->net_salary,
                'current_payable' => (float) $r->current_payable,
                'total_paid' => $r->totalPaid(),
                'remaining_balance' => $r->remainingBalance(),
                'status' => $r->status,
            ])->values(),
        ];
    }

    // --- Outstanding Salary Report: every record with a remaining balance ---

    public function outstanding(Request $request)
    {
        $this->authorize('read-salary-reports');

        return Inertia::render('Salary/Reports/Outstanding', $this->outstandingPayload());
    }

    public function outstandingPrint(Request $request)
    {
        $this->authorize('export-salary-reports');

        return view('salary.list-report-print', $this->outstandingListPrintPayload() + ['isPdf' => false]);
    }

    public function outstandingPdf(Request $request)
    {
        $this->authorize('export-salary-reports');

        return Pdf::loadView('salary.list-report-print', $this->outstandingListPrintPayload() + ['isPdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('salary-outstanding-' . now()->format('Ymd-His') . '.pdf');
    }

    private function outstandingRows()
    {
        // One row per employee (their latest record, whose balance already includes everything carried
        // forward). Listing every month's record would repeat the same arrears once per month.
        return \App\Services\SalaryBalanceSummary::outstandingRows();
    }

    private function outstandingPayload(): array
    {
        $rows = $this->outstandingRows();

        return [
            'rows' => $rows->map(fn (SalaryRecord $r) => [
                'staff_name' => optional($r->staff)->name,
                'staff_number' => optional($r->staff)->staff_number,
                'period_label' => optional($r->period)->label,
                'current_payable' => (float) $r->current_payable,
                'total_paid' => $r->totalPaid(),
                'remaining_balance' => $r->remainingBalance(),
            ])->values(),
            'total_outstanding' => round($rows->sum(fn (SalaryRecord $r) => $r->remainingBalance()), 2),
        ];
    }

    private function outstandingListPrintPayload(): array
    {
        $rows = $this->outstandingRows();

        return [
            'title' => __('Salary Outstanding Report'),
            'generatedBy' => optional(auth()->user())->name,
            'columns' => [
                ['key' => 'staff_number', 'label' => 'Staff No.'],
                ['key' => 'staff_name', 'label' => 'Employee'],
                ['key' => 'period_label', 'label' => 'Period'],
                ['key' => 'current_payable', 'label' => 'Payable', 'align' => 'right'],
                ['key' => 'total_paid', 'label' => 'Paid', 'align' => 'right'],
                ['key' => 'remaining_balance', 'label' => 'Remaining', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn (SalaryRecord $r) => [
                'staff_number' => optional($r->staff)->staff_number,
                'staff_name' => optional($r->staff)->name,
                'period_label' => optional($r->period)->label,
                'current_payable' => (float) $r->current_payable,
                'total_paid' => $r->totalPaid(),
                'remaining_balance' => $r->remainingBalance(),
            ])->values(),
        ];
    }

    // --- Advance Report ---

    public function advances(Request $request)
    {
        $this->authorize('read-salary-reports');

        $advances = SalaryAdvance::with('staff:id,name,staff_number')->orderByDesc('id')->get();

        return Inertia::render('Salary/Reports/Advances', [
            'rows' => $advances->map(fn (SalaryAdvance $a) => [
                'reference' => $a->reference, 'staff_name' => optional($a->staff)->name,
                'amount' => (float) $a->amount, 'outstanding' => $a->outstandingBalance(), 'status' => $a->status,
            ])->values(),
        ]);
    }

    public function advancesPrint(Request $request)
    {
        $this->authorize('export-salary-reports');

        return view('salary.list-report-print', $this->advancesListPrintPayload() + ['isPdf' => false]);
    }

    public function advancesPdf(Request $request)
    {
        $this->authorize('export-salary-reports');

        return Pdf::loadView('salary.list-report-print', $this->advancesListPrintPayload() + ['isPdf' => true])
            ->setPaper('a4')
            ->download('salary-advances-' . now()->format('Ymd-His') . '.pdf');
    }

    private function advancesListPrintPayload(): array
    {
        $advances = SalaryAdvance::with('staff:id,name,staff_number')->orderByDesc('id')->get();

        return [
            'title' => __('Salary Advance Report'),
            'generatedBy' => optional(auth()->user())->name,
            'columns' => [
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'staff_name', 'label' => 'Employee'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'align' => 'right'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $advances->map(fn (SalaryAdvance $a) => [
                'reference' => $a->reference, 'staff_name' => optional($a->staff)->name,
                'amount' => (float) $a->amount, 'outstanding' => $a->outstandingBalance(), 'status' => $a->status,
            ])->values(),
        ];
    }

    // --- Loan Report ---

    public function loans(Request $request)
    {
        $this->authorize('read-salary-reports');

        $loans = SalaryLoan::with('staff:id,name,staff_number')->orderByDesc('id')->get();

        return Inertia::render('Salary/Reports/Loans', [
            'rows' => $loans->map(fn (SalaryLoan $l) => [
                'reference' => $l->reference, 'staff_name' => optional($l->staff)->name,
                'amount' => (float) $l->principal_amount, 'outstanding' => $l->outstandingPrincipal(), 'status' => $l->status,
            ])->values(),
        ]);
    }

    public function loansPrint(Request $request)
    {
        $this->authorize('export-salary-reports');

        return view('salary.list-report-print', $this->loansListPrintPayload() + ['isPdf' => false]);
    }

    public function loansPdf(Request $request)
    {
        $this->authorize('export-salary-reports');

        return Pdf::loadView('salary.list-report-print', $this->loansListPrintPayload() + ['isPdf' => true])
            ->setPaper('a4')
            ->download('salary-loans-' . now()->format('Ymd-His') . '.pdf');
    }

    private function loansListPrintPayload(): array
    {
        $loans = SalaryLoan::with('staff:id,name,staff_number')->orderByDesc('id')->get();

        return [
            'title' => __('Salary Loan Report'),
            'generatedBy' => optional(auth()->user())->name,
            'columns' => [
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'staff_name', 'label' => 'Employee'],
                ['key' => 'amount', 'label' => 'Principal', 'align' => 'right'],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'align' => 'right'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $loans->map(fn (SalaryLoan $l) => [
                'reference' => $l->reference, 'staff_name' => optional($l->staff)->name,
                'amount' => (float) $l->principal_amount, 'outstanding' => $l->outstandingPrincipal(), 'status' => $l->status,
            ])->values(),
        ];
    }

    // --- Payment History ---

    public function paymentHistory(Request $request)
    {
        $this->authorize('read-salary-reports');

        $filters = $request->all('staff_id', 'date_from', 'date_to');

        $payments = SalaryPayment::query()
            ->with(['record.staff:id,name,staff_number', 'record.period:id,label', 'paidBy:id,name'])
            ->when($filters['staff_id'] ?? null, fn ($q, $id) => $q->whereHas('record', fn ($r) => $r->where('staff_id', $id)))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('payment_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('payment_date', '<=', $d))
            ->orderByDesc('payment_date')
            ->paginate()
            ->withQueryString();

        return Inertia::render('Salary/Reports/PaymentHistory', [
            'filters' => $filters,
            'payments' => $payments->through(fn (SalaryPayment $p) => [
                'id' => $p->id,
                'staff_name' => optional(optional($p->record)->staff)->name,
                'period_label' => optional(optional($p->record)->period)->label,
                'amount' => (float) $p->amount,
                'payment_date' => optional($p->payment_date)->format('Y-m-d'),
                'payment_method' => $p->payment_method,
                'payment_reference' => $p->payment_reference,
                'paid_by_name' => optional($p->paidBy)->name,
            ]),
        ]);
    }

    public function paymentHistoryPrint(Request $request)
    {
        $this->authorize('export-salary-reports');

        return view('salary.list-report-print', $this->paymentHistoryListPrintPayload($request) + ['isPdf' => false]);
    }

    public function paymentHistoryPdf(Request $request)
    {
        $this->authorize('export-salary-reports');

        return Pdf::loadView('salary.list-report-print', $this->paymentHistoryListPrintPayload($request) + ['isPdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('salary-payment-history-' . now()->format('Ymd-His') . '.pdf');
    }

    private function paymentHistoryListPrintPayload(Request $request): array
    {
        $filters = $request->all('staff_id', 'date_from', 'date_to');

        $payments = SalaryPayment::query()
            ->with(['record.staff:id,name,staff_number', 'record.period:id,label', 'paidBy:id,name'])
            ->when($filters['staff_id'] ?? null, fn ($q, $id) => $q->whereHas('record', fn ($r) => $r->where('staff_id', $id)))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('payment_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('payment_date', '<=', $d))
            ->orderByDesc('payment_date')
            ->get();

        return [
            'title' => __('Salary Payment History'),
            'generatedBy' => optional(auth()->user())->name,
            'columns' => [
                ['key' => 'staff_name', 'label' => 'Employee'],
                ['key' => 'period_label', 'label' => 'Period'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
                ['key' => 'payment_date', 'label' => 'Date'],
                ['key' => 'payment_method', 'label' => 'Method'],
                ['key' => 'payment_reference', 'label' => 'Reference'],
                ['key' => 'paid_by_name', 'label' => 'Paid By'],
            ],
            'rows' => $payments->map(fn (SalaryPayment $p) => [
                'staff_name' => optional(optional($p->record)->staff)->name,
                'period_label' => optional(optional($p->record)->period)->label,
                'amount' => (float) $p->amount,
                'payment_date' => optional($p->payment_date)->format('Y-m-d'),
                'payment_method' => $p->payment_method,
                'payment_reference' => $p->payment_reference,
                'paid_by_name' => optional($p->paidBy)->name,
            ])->values(),
        ];
    }
}
