<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Models\SalaryLoan;
use App\Models\SalaryOpeningBalance;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use App\Models\Staff;
use App\Services\SalaryCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SalaryOpeningBalanceController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-salary-opening-balances');

        $filters = $request->all('search', 'state');

        $all = SalaryOpeningBalance::with(['staff:id,name,staff_number', 'loan', 'advance', 'appliedRecord.period:id,label'])
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->where(function ($sq) use ($s) {
                    $sq->where('reference', 'like', "%{$s}%")
                        ->orWhere('remarks', 'like', "%{$s}%")
                        ->orWhereHas('staff', fn ($st) => $st->where('name', 'like', "%{$s}%")->orWhere('staff_number', 'like', "%{$s}%"));
                });
            })
            ->orderByDesc('id')
            ->get();

        $rows = $all->map(fn (SalaryOpeningBalance $b) => $this->present($b));

        if ($state = $filters['state'] ?? null) {
            $rows = $rows->filter(fn ($r) => $r['state'] === $state)->values();
        }

        $perPage = 15;
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $everything = SalaryOpeningBalance::with(['loan', 'advance', 'appliedRecord'])->get();

        return Inertia::render('Salary/OpeningBalances/Index', [
            'filters' => $filters,
            'balances' => $paginator,
            'summary' => [
                'entries' => $everything->count(),
                'outstanding_total' => round($everything->sum('outstanding_salary'), 2),
                'outstanding_pending' => round($everything->filter(fn ($b) => $b->hasOutstanding() && ! $b->outstandingApplied())->sum('outstanding_salary'), 2),
                'loan_balance' => round($everything->sum(fn ($b) => $b->loan && $b->loan->status === SalaryLoan::STATUS_ACTIVE ? $b->loan->outstandingPrincipal() : 0), 2),
                'advance_balance' => round($everything->sum(fn ($b) => $b->advance && $b->advance->status === SalaryAdvance::STATUS_ACTIVE ? $b->advance->outstandingBalance() : 0), 2),
            ],
            'can' => [
                'create' => $request->user()->can('create-salary-opening-balances'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create-salary-opening-balances');

        return Inertia::render('Salary/OpeningBalances/Form', [
            'balance' => null,
            'staffs' => $this->availableStaff(),
        ]);
    }

    public function bulk(Request $request)
    {
        $this->authorize('create-salary-opening-balances');

        return Inertia::render('Salary/OpeningBalances/Bulk', [
            'staffs' => $this->availableStaff(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create-salary-opening-balances');

        $data = $this->validated($request, null);

        $balance = DB::transaction(fn () => $this->persist($data, null, $request->user()->id));

        log_activity(__('Salary opening balance recorded.'), ['reference' => $balance->reference], $balance, 'Salary Opening Balance');

        \App\Services\SalaryNotifier::fire('opening_balance_created', $balance, $request->user(), __('Opening Balance Recorded'), 'An opening balance (' . $balance->reference . ') for ' . optional($balance->staff)->name . ' was recorded by ' . $request->user()->name . '.', ['reference' => $balance->reference]);

        return redirect()->route('salary.opening-balances.index')->with('message', __('Salary opening balance recorded.'));
    }

    public function bulkStore(Request $request)
    {
        $this->authorize('create-salary-opening-balances');

        $common = $request->validate([
            'balance_from' => 'required|date',
            'balance_to' => 'required|date|after_or_equal:balance_from',
            'rows' => 'required|array|min:1',
        ]);

        $created = 0;
        $skipped = [];

        DB::transaction(function () use ($request, $common, &$created, &$skipped) {
            foreach ($request->input('rows', []) as $i => $row) {
                $hasAmount = (float) ($row['outstanding_salary'] ?? 0) > 0
                    || (float) ($row['loan_amount'] ?? 0) > 0
                    || (float) ($row['advance_amount'] ?? 0) > 0;

                if (! $hasAmount) {
                    continue;
                }

                $staff = Staff::find($row['staff_id'] ?? null);
                if (! $staff) {
                    continue;
                }

                if (SalaryOpeningBalance::where('staff_id', $staff->id)->exists()) {
                    $skipped[] = $staff->name;

                    continue;
                }

                $sub = $this->validateRow($row + $common, "rows.{$i}.");
                $balance = $this->persist($sub, null, $request->user()->id);
                log_activity(__('Salary opening balance recorded.'), ['reference' => $balance->reference], $balance, 'Salary Opening Balance');
                $created++;
            }
        });

        if ($created === 0) {
            throw ValidationException::withMessages(['rows' => __('Enter at least one amount (outstanding salary, loan or advance) for a staff member.')]);
        }

        \App\Services\SalaryNotifier::fire('opening_balance_created', null, $request->user(), __('Opening Balances Recorded'), $created . ' opening balance(s) were recorded in bulk by ' . $request->user()->name . '.', ['reference' => (string) $created . ' record(s)']);

        $message = __('Salary opening balance recorded.') . " ({$created})";
        if ($skipped) {
            $message .= ' ' . __('Skipped (already has an opening balance): :names.', ['names' => implode(', ', $skipped)]);
        }

        return redirect()->route('salary.opening-balances.index')->with('message', $message);
    }

    public function edit(Request $request, $id)
    {
        $this->authorize('read-salary-opening-balances');

        $balance = SalaryOpeningBalance::with(['staff:id,name,staff_number', 'loan', 'advance', 'appliedRecord.period:id,label'])->findOrFail($id);

        return Inertia::render('Salary/OpeningBalances/Form', [
            'balance' => $this->present($balance) + [
                'balance_from' => $balance->balance_from->format('Y-m-d'),
                'balance_to' => $balance->balance_to->format('Y-m-d'),
                'staff_id' => $balance->staff_id,
                'loan_installments' => $balance->loan_installments,
                'advance_installments' => $balance->advance_installments,
            ],
            'staffs' => [],
            'can' => [
                'update' => $request->user()->can('update-salary-opening-balances'),
                'delete' => $request->user()->can('delete-salary-opening-balances'),
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorize('update-salary-opening-balances');

        $balance = SalaryOpeningBalance::findOrFail($id);
        abort_if($balance->isLocked(), 422, 'This opening balance has already been used by payroll. Correct it with a salary adjustment instead.');

        $data = $this->validated($request, $balance);
        $data['staff_id'] = $balance->staff_id; // the employee never changes after creation

        DB::transaction(fn () => $this->persist($data, $balance, $request->user()->id));

        log_activity(__('Salary opening balance updated.'), ['reference' => $balance->reference], $balance, 'Salary Opening Balance');

        \App\Services\SalaryNotifier::fire('opening_balance_updated', $balance->fresh(), $request->user(), __('Opening Balance Edited'), 'Opening balance ' . $balance->reference . ' for ' . optional($balance->staff)->name . ' was edited by ' . $request->user()->name . '.', ['reference' => $balance->reference]);

        return back()->with('message', __('Salary opening balance updated.'));
    }

    public function destroy(Request $request, $id)
    {
        $this->authorize('delete-salary-opening-balances');

        $balance = SalaryOpeningBalance::findOrFail($id);
        abort_if($balance->isLocked(), 422, 'This opening balance has already been used by payroll and cannot be deleted. Correct it with a salary adjustment instead.');

        DB::transaction(function () use ($balance, $request) {
            foreach ([$balance->loan, $balance->advance] as $linked) {
                if ($linked && in_array($linked->status, ['Active', 'Pending', 'Approved'], true)) {
                    $linked->update(['status' => 'Cancelled']);
                }
            }
            $balance->update(['deleted_by' => $request->user()->id]);
            $balance->delete();
        });

        log_activity(__('Salary opening balance deleted.'), ['reference' => $balance->reference], $balance, 'Salary Opening Balance');

        \App\Services\SalaryNotifier::fire('opening_balance_deleted', $balance, $request->user(), __('Opening Balance Deleted'), 'Opening balance ' . $balance->reference . ' for ' . optional($balance->staff)->name . ' was deleted by ' . $request->user()->name . '.', ['reference' => $balance->reference]);

        return redirect()->route('salary.opening-balances.index')->with('message', __('Salary opening balance deleted.'));
    }

    /** Pushes a still-pending unpaid-salary amount onto the employee's current Draft salary record instead of waiting for the next one. */
    public function apply(Request $request, $id, SalaryCalculator $calculator)
    {
        $this->authorize('update-salary-opening-balances');

        $balance = SalaryOpeningBalance::findOrFail($id);
        abort_unless($balance->hasOutstanding() && ! $balance->outstandingApplied(), 422, 'There is no pending unpaid salary to apply for this entry.');

        $record = SalaryRecord::where('staff_id', $balance->staff_id)
            ->where('status', SalaryRecord::STATUS_DRAFT)
            ->whereHas('period', fn ($q) => $q->whereIn('status', [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING]))
            ->with('period')
            ->get()
            ->sortByDesc(fn (SalaryRecord $r) => $r->period->period_start)
            ->first();

        if (! $record) {
            return back()->with('error', __('This employee has no salary being prepared right now. The amount will be added automatically to the next salary you prepare.'));
        }

        $calculator->applyPendingOpeningBalance($record, $request->user()->id);

        log_activity(__('Salary opening balance applied.'), ['reference' => $balance->reference, 'period' => $record->period->label], $balance, 'Salary Opening Balance');

        \App\Services\SalaryNotifier::fire('opening_balance_applied', $balance, $request->user(), __('Opening Balance Applied'), 'Opening balance ' . $balance->reference . ' for ' . optional($balance->staff)->name . ' was added to ' . $record->period->label . ' by ' . $request->user()->name . '.', ['reference' => $balance->reference, 'period' => $record->period->label, 'batch' => $record->period->batch_reference]);

        return back()->with('message', __('Unpaid salary added to :period.', ['period' => $record->period->label]));
    }

    // ---------------------------------------------------------------------

    private function availableStaff()
    {
        $taken = SalaryOpeningBalance::pluck('staff_id');

        return Staff::payroll()
            ->whereNotIn('id', $taken)
            ->orderBy('name')
            ->get(['id', 'name', 'staff_number', 'position']);
    }

    private function validated(Request $request, ?SalaryOpeningBalance $existing): array
    {
        return $this->validateRow($request->all(), '', $existing);
    }

    /** @param array<string,mixed> $input */
    private function validateRow(array $input, string $prefix = '', ?SalaryOpeningBalance $existing = null): array
    {
        $validator = validator($input, [
            'staff_id' => [$existing ? 'nullable' : 'required', 'exists:staffs,id', Rule::unique('salary_opening_balances', 'staff_id')->whereNull('deleted_at')->ignore($existing?->id)],
            'balance_from' => 'required|date',
            'balance_to' => 'required|date|after_or_equal:balance_from',
            'outstanding_salary' => 'nullable|numeric|min:0|max:9999999999999',
            'loan_amount' => 'nullable|numeric|min:0|max:9999999999999',
            'loan_installments' => 'nullable|required_if:loan_amount,>0|integer|min:1|max:600',
            'advance_amount' => 'nullable|numeric|min:0|max:9999999999999',
            'advance_installments' => 'nullable|integer|min:1|max:600',
            'remarks' => 'nullable|string|max:2000',
        ], [
            'staff_id.unique' => __('This employee already has a salary opening balance.'),
        ]);

        $validator->after(function ($v) use ($input) {
            $out = (float) ($input['outstanding_salary'] ?? 0);
            $loan = (float) ($input['loan_amount'] ?? 0);
            $adv = (float) ($input['advance_amount'] ?? 0);

            if ($out <= 0 && $loan <= 0 && $adv <= 0) {
                $v->errors()->add('outstanding_salary', __('Enter at least one amount: outstanding salary, loan balance or advance balance.'));
            }
            if ($loan > 0 && (int) ($input['loan_installments'] ?? 0) < 1) {
                $v->errors()->add('loan_installments', __('Enter how many loan instalments remain.'));
            }
            if ($adv > 0 && (int) ($input['advance_installments'] ?? 0) < 1) {
                $v->errors()->add('advance_installments', __('Enter how many advance instalments remain.'));
            }
        });

        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $key => $messages) {
                $errors[$prefix . $key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        $d = $validator->validated();

        return [
            'staff_id' => $d['staff_id'] ?? null,
            'balance_from' => $d['balance_from'],
            'balance_to' => $d['balance_to'],
            'outstanding_salary' => round((float) ($d['outstanding_salary'] ?? 0), 2),
            'loan_amount' => round((float) ($d['loan_amount'] ?? 0), 2),
            'loan_installments' => ! empty($d['loan_amount']) && (float) $d['loan_amount'] > 0 ? (int) $d['loan_installments'] : null,
            'advance_amount' => round((float) ($d['advance_amount'] ?? 0), 2),
            'advance_installments' => ! empty($d['advance_amount']) && (float) $d['advance_amount'] > 0 ? (int) $d['advance_installments'] : null,
            'remarks' => $d['remarks'] ?? null,
        ];
    }

    /** Creates or updates the entry and keeps its linked Active loan / advance in step. */
    private function persist(array $data, ?SalaryOpeningBalance $balance, int $userId): SalaryOpeningBalance
    {
        $isNew = $balance === null;

        if ($isNew) {
            $balance = SalaryOpeningBalance::create([
                'reference' => $this->nextReference('salary_opening_balances', 'SOB', 'reference'),
                'staff_id' => $data['staff_id'],
                'created_by_user_id' => $userId,
                'extra_attributes' => ['opening_balance' => true],
            ] + collect($data)->only(['balance_from', 'balance_to', 'outstanding_salary', 'loan_amount', 'loan_installments', 'advance_amount', 'advance_installments', 'remarks'])->all());
        } else {
            $balance->update(collect($data)->only(['balance_from', 'balance_to', 'outstanding_salary', 'loan_amount', 'loan_installments', 'advance_amount', 'advance_installments', 'remarks'])->all() + ['updated_by_user_id' => $userId]);
        }

        $note = 'Opening balance ' . $balance->reference . ', brought forward from before WIMS (' . $balance->balance_from->format('d M Y') . ' to ' . $balance->balance_to->format('d M Y') . ').'
            . ($balance->remarks ? ' ' . $balance->remarks : '');

        // Loan part
        if ((float) $balance->loan_amount > 0) {
            $fields = [
                'loan_date' => $balance->balance_to->format('Y-m-d'),
                'principal_amount' => $balance->loan_amount,
                'installments_count' => $balance->loan_installments,
                'installment_amount' => round($balance->loan_amount / max(1, (int) $balance->loan_installments), 2),
                'notes' => $note,
            ];
            if ($balance->loan) {
                $balance->loan->update($fields + ['status' => SalaryLoan::STATUS_ACTIVE]);
            } else {
                $loan = SalaryLoan::create($fields + [
                    'staff_id' => $balance->staff_id,
                    'reference' => $this->nextReference('salary_loans', 'SLN', 'reference'),
                    'interest_rate' => 0,
                    'repayment_method' => SalaryLoan::METHOD_FIXED_INSTALLMENT,
                    'status' => SalaryLoan::STATUS_ACTIVE,
                    'approved_by_user_id' => $userId,
                    'approved_at' => now(),
                    'created_by_user_id' => $userId,
                    'extra_attributes' => ['opening_balance' => $balance->reference],
                ]);
                $balance->update(['salary_loan_id' => $loan->id]);
            }
        } elseif ($balance->loan && $balance->loan->status !== SalaryLoan::STATUS_CANCELLED) {
            $balance->loan->update(['status' => SalaryLoan::STATUS_CANCELLED]);
        }

        // Advance part
        if ((float) $balance->advance_amount > 0) {
            $fields = [
                'advance_date' => $balance->balance_to->format('Y-m-d'),
                'amount' => $balance->advance_amount,
                'installments_count' => $balance->advance_installments,
                'installment_amount' => round($balance->advance_amount / max(1, (int) $balance->advance_installments), 2),
                'reason' => 'Opening balance brought forward',
                'notes' => $note,
            ];
            if ($balance->advance) {
                $balance->advance->update($fields + ['status' => SalaryAdvance::STATUS_ACTIVE]);
            } else {
                $advance = SalaryAdvance::create($fields + [
                    'staff_id' => $balance->staff_id,
                    'reference' => $this->nextReference('salary_advances', 'SAD', 'reference'),
                    'repayment_method' => 'Fixed Installment',
                    'status' => SalaryAdvance::STATUS_ACTIVE,
                    'approved_by_user_id' => $userId,
                    'approved_at' => now(),
                    'created_by_user_id' => $userId,
                    'extra_attributes' => ['opening_balance' => $balance->reference],
                ]);
                $balance->update(['salary_advance_id' => $advance->id]);
            }
        } elseif ($balance->advance && $balance->advance->status !== SalaryAdvance::STATUS_CANCELLED) {
            $balance->advance->update(['status' => SalaryAdvance::STATUS_CANCELLED]);
        }

        return $balance->fresh();
    }

    private function nextReference(string $table, string $code, string $column): string
    {
        $prefix = $code . '-' . now()->format('Y') . '-';
        $prefixLength = strlen($prefix) + 1;
        $maxSuffix = (int) DB::table($table)
            ->selectRaw("MAX(CAST(SUBSTRING({$column}, {$prefixLength}) AS UNSIGNED)) as max_suffix")
            ->where($column, 'like', "{$prefix}%")
            ->value('max_suffix');

        return $prefix . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    private function present(SalaryOpeningBalance $b): array
    {
        $loan = $b->loan;
        $advance = $b->advance;
        $locked = $b->isLocked();

        if ($b->hasOutstanding()) {
            $outState = $b->outstandingApplied() ? 'applied' : 'pending';
        } else {
            $outState = 'none';
        }

        $usedByPayroll = $locked;
        $hasSomethingPending = ($b->hasOutstanding() && ! $b->outstandingApplied())
            || ($loan && $loan->status === SalaryLoan::STATUS_ACTIVE && $loan->repayments->isEmpty())
            || ($advance && $advance->status === SalaryAdvance::STATUS_ACTIVE && $advance->repayments->isEmpty());

        return [
            'id' => $b->id,
            'reference' => $b->reference,
            'staff_name' => $b->staff?->name,
            'staff_number' => $b->staff?->staff_number,
            'balance_from' => $b->balance_from?->format('Y-m-d'),
            'balance_to' => $b->balance_to?->format('Y-m-d'),
            'outstanding_salary' => (float) $b->outstanding_salary,
            'outstanding_state' => $outState,
            'applied_period' => $b->outstandingApplied() ? $b->appliedRecord?->period?->label : null,
            'loan_amount' => (float) $b->loan_amount,
            'loan_installments' => $b->loan_installments,
            'loan_reference' => $loan?->reference,
            'loan_id' => $loan?->id,
            'loan_status' => $loan?->status,
            'loan_outstanding' => $loan ? $loan->outstandingPrincipal() : 0,
            'advance_amount' => (float) $b->advance_amount,
            'advance_installments' => $b->advance_installments,
            'advance_reference' => $advance?->reference,
            'advance_id' => $advance?->id,
            'advance_status' => $advance?->status,
            'advance_outstanding' => $advance ? $advance->outstandingBalance() : 0,
            'remarks' => $b->remarks,
            'locked' => $locked,
            'state' => $usedByPayroll ? 'in_use' : 'pending',
            'created_at' => $b->created_at?->format('Y-m-d'),
        ];
    }
}
