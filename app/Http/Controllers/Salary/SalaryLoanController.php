<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\SalaryLoan;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SalaryLoanController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-salary-loans');

        $filters = $request->all('search', 'status');

        $loans = SalaryLoan::query()
            ->with('staff:id,name,staff_number')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('reference', 'like', "%{$s}%")->orWhereHas('staff', fn ($sq) => $sq->where('name', 'like', "%{$s}%")))
            ->orderByDesc('id')
            ->paginate()
            ->withQueryString();

        $active = SalaryLoan::where('status', SalaryLoan::STATUS_ACTIVE)->get();

        return Inertia::render('Salary/Loans/Index', [
            'filters' => $filters,
            'statuses' => SalaryLoan::statuses(),
            'loans' => $loans->through(fn (SalaryLoan $l) => $this->presentSummary($l)),
            'summary' => [
                'total_active' => $active->count(),
                'total_outstanding' => round($active->sum(fn (SalaryLoan $l) => $l->outstandingPrincipal()), 2),
                'total_pending' => SalaryLoan::where('status', SalaryLoan::STATUS_PENDING)->count(),
                'total_disbursed' => round(SalaryLoan::whereIn('status', [SalaryLoan::STATUS_ACTIVE, SalaryLoan::STATUS_COMPLETED])->sum('principal_amount'), 2),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create-salary-loans');

        return Inertia::render('Salary/Loans/Form', [
            'staffs' => Staff::payroll()->orderBy('name')->get(['id', 'name', 'staff_number', 'basic_salary']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create-salary-loans');

        $data = $this->validated($request);

        $loan = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $loan = SalaryLoan::create($data + [
                    'reference' => $this->nextReference(),
                    'repayment_method' => SalaryLoan::METHOD_FIXED_INSTALLMENT,
                    'status' => SalaryLoan::STATUS_PENDING,
                    'created_by_user_id' => $request->user()->id,
                ]);
                break;
            } catch (\Illuminate\Database\QueryException $e) {
                if ((string) $e->getCode() !== '23000' || $attempt === 2) {
                    throw $e;
                }
                $loan = null;
            }
        }

        if ($request->hasFile('attachments')) {
            $loan->saveAttachments($request->file('attachments'), 'Salary Loan');
        }

        log_activity(__('Salary Loan created.'), ['reference' => $loan->reference], $loan, 'Salary Loan');

        \App\Services\SalaryNotifier::fire('loan_created', $loan, $request->user(), __('Salary Loan Requested'), 'Salary loan ' . $loan->reference . ' for ' . optional($loan->staff)->name . ' was requested by ' . $request->user()->name . '.', ['reference' => $loan->reference, 'amount' => \App\Services\SalaryNotifier::money($loan->principal_amount)]);

        return redirect()->route('salary.loans.show', $loan->id)->with('message', __('Salary Loan created.'));
    }

    public function show(Request $request, $id)
    {
        $this->authorize('read-salary-loans');

        $loan = SalaryLoan::with(['staff:id,name,staff_number', 'approvedBy:id,name', 'repayments.record.period:id,label', 'attachments'])->findOrFail($id);

        return Inertia::render('Salary/Loans/Show', ['loan' => $this->presentDetail($loan)]);
    }

    public function update(Request $request, $id)
    {
        $this->authorize('update-salary-loans');

        $loan = SalaryLoan::findOrFail($id);
        abort_unless($loan->status === SalaryLoan::STATUS_PENDING, 422, 'Only a Pending salary loan can be edited.');

        $loan->update($this->validated($request));

        log_activity(__('Salary Loan updated.'), ['reference' => $loan->reference], $loan, 'Salary Loan');

        \App\Services\SalaryNotifier::fire('loan_updated', $loan, $request->user(), __('Salary Loan Edited'), 'Salary loan ' . $loan->reference . ' for ' . optional($loan->staff)->name . ' was edited by ' . $request->user()->name . '.', ['reference' => $loan->reference, 'amount' => \App\Services\SalaryNotifier::money($loan->principal_amount)]);

        return back()->with('message', __('Salary Loan updated.'));
    }

    public function destroy(Request $request, $id)
    {
        $this->authorize('delete-salary-loans');

        $loan = SalaryLoan::findOrFail($id);
        abort_unless($loan->status === SalaryLoan::STATUS_PENDING, 422, 'Only a Pending salary loan can be deleted.');

        $loan->delete();

        log_activity(__('Salary Loan deleted.'), ['reference' => $loan->reference], $loan, 'Salary Loan');

        \App\Services\SalaryNotifier::fire('loan_deleted', $loan, $request->user(), __('Salary Loan Deleted'), 'Salary loan ' . $loan->reference . ' for ' . optional($loan->staff)->name . ' was deleted by ' . $request->user()->name . '.', ['reference' => $loan->reference, 'amount' => \App\Services\SalaryNotifier::money($loan->principal_amount)]);

        return redirect()->route('salary.loans.index')->with('message', __('Salary Loan deleted.'));
    }

    public function approve(Request $request, $id)
    {
        $this->authorize('approve-salary-loans');

        DB::transaction(function () use ($id, $request) {
            $loan = SalaryLoan::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($loan->status === SalaryLoan::STATUS_PENDING, 422, 'This loan must be Pending for this action.');

            $loan->update([
                'status' => SalaryLoan::STATUS_ACTIVE,
                'approved_by_user_id' => $request->user()->id,
                'approved_at' => now(),
            ]);
            log_activity(__('Salary Loan approved.'), ['reference' => $loan->reference], $loan, 'Salary Loan');
        });

        $loan = SalaryLoan::with('staff')->find($id);
        \App\Services\SalaryNotifier::fire('loan_approved', $loan, $request->user(), __('Salary Loan Approved'), 'Salary loan ' . $loan->reference . ' for ' . optional($loan->staff)->name . ' was approved by ' . $request->user()->name . '.', ['reference' => $loan->reference, 'amount' => \App\Services\SalaryNotifier::money($loan->principal_amount)]);

        return back()->with('message', __('Salary Loan approved.'));
    }

    public function reject(Request $request, $id)
    {
        $this->authorize('approve-salary-loans');

        DB::transaction(function () use ($id) {
            $loan = SalaryLoan::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($loan->status === SalaryLoan::STATUS_PENDING, 422, 'This loan must be Pending for this action.');

            $loan->update(['status' => SalaryLoan::STATUS_REJECTED]);
            log_activity(__('Salary Loan rejected.'), ['reference' => $loan->reference], $loan, 'Salary Loan');
        });

        $loan = SalaryLoan::with('staff')->find($id);
        \App\Services\SalaryNotifier::fire('loan_rejected', $loan, $request->user(), __('Salary Loan Rejected'), 'Salary loan ' . $loan->reference . ' for ' . optional($loan->staff)->name . ' was rejected by ' . $request->user()->name . '.', ['reference' => $loan->reference, 'amount' => \App\Services\SalaryNotifier::money($loan->principal_amount)]);

        return back()->with('message', __('Salary Loan rejected.'));
    }

    public function cancel(Request $request, $id)
    {
        $this->authorize('approve-salary-loans');

        DB::transaction(function () use ($id) {
            $loan = SalaryLoan::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($loan->status === SalaryLoan::STATUS_ACTIVE, 422, 'This loan must be Active for this action.');

            $loan->update(['status' => SalaryLoan::STATUS_CANCELLED]);
            log_activity(__('Salary Loan cancelled.'), ['reference' => $loan->reference], $loan, 'Salary Loan');
        });

        $loan = SalaryLoan::with('staff')->find($id);
        \App\Services\SalaryNotifier::fire('loan_cancelled', $loan, $request->user(), __('Salary Loan Cancelled'), 'Salary loan ' . $loan->reference . ' for ' . optional($loan->staff)->name . ' was cancelled by ' . $request->user()->name . '.', ['reference' => $loan->reference, 'amount' => \App\Services\SalaryNotifier::money($loan->principal_amount)]);

        return back()->with('message', __('Salary Loan cancelled.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'staff_id' => 'required|exists:staffs,id',
            'loan_date' => 'required|date',
            'principal_amount' => 'required|numeric|min:0.01',
            'installments_count' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]) + [
            'interest_rate' => 0,
            'installment_amount' => round($request->input('principal_amount') / max(1, (int) $request->input('installments_count')), 2),
        ];
    }

    private function nextReference(): string
    {
        $prefix = 'SLN-' . now()->format('Y') . '-';
        $prefixLength = strlen($prefix) + 1;
        $maxSuffix = (int) DB::table('salary_loans')
            ->selectRaw("MAX(CAST(SUBSTRING(reference, {$prefixLength}) AS UNSIGNED)) as max_suffix")
            ->where('reference', 'like', "{$prefix}%")
            ->value('max_suffix');

        return $prefix . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    private function presentSummary(SalaryLoan $loan): array
    {
        $amount = (float) $loan->principal_amount;
        $outstanding = $loan->outstandingPrincipal();
        $progress = $amount > 0 ? round((($amount - $outstanding) / $amount) * 100) : 0;

        return [
            'id' => $loan->id,
            'reference' => $loan->reference,
            'staff_name' => optional($loan->staff)->name,
            'loan_date' => optional($loan->loan_date)->format('Y-m-d'),
            'principal_amount' => $amount,
            'installment_amount' => (float) $loan->installment_amount,
            'outstanding_principal' => $outstanding,
            'repayment_progress' => $progress,
            'status' => $loan->status,
        ];
    }

    private function presentDetail(SalaryLoan $loan): array
    {
        return array_merge($this->presentSummary($loan), [
            'notes' => $loan->notes,
            'installments_count' => $loan->installments_count,
            'interest_rate' => (float) $loan->interest_rate,
            'approved_by_name' => optional($loan->approvedBy)->name,
            'repayments' => $loan->repayments->map(fn ($r) => [
                'period_label' => optional(optional($r->record)->period)->label,
                'amount' => (float) $r->amount,
                'date' => optional($r->created_at)->format('Y-m-d'),
            ])->values(),
        ]);
    }
}
