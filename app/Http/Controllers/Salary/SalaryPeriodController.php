<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceRepayment;
use App\Models\SalaryLoan;
use App\Models\SalaryLoanRepayment;
use App\Models\SalaryNotificationRule;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SalaryPeriodController extends Controller
{
    // Route names are salary.periods.* — the route-name-segment-0-derived
    // default ability (read-salary, create-salary, ...) wouldn't match this
    // module's actual per-submodule permissions (read-salary-periods etc.),
    // so every action authorizes explicitly instead, matching the
    // ComplianceRenewalController/LabourController convention.
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-salary-periods');

        $filters = $request->all('search', 'status');

        $periods = SalaryPeriod::query()
            ->filter($filters)
            ->withCount('records')
            ->orderByDesc('period_start')
            ->paginate()
            ->withQueryString()
            ->through(fn (SalaryPeriod $p) => $this->presentSummary($p));

        return Inertia::render('Salary/Periods/Index', [
            'filters' => $filters,
            'periods' => $periods,
            'statuses' => SalaryPeriod::statuses(),
            'can' => ['create' => $request->user()->can('create', SalaryPeriod::class)],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', SalaryPeriod::class);

        return Inertia::render('Salary/Periods/Form');
    }

    public function store(Request $request)
    {
        $this->authorize('create', SalaryPeriod::class);

        $data = $request->validate([
            'label' => 'required|string|max:190',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'notes' => 'nullable|string|max:2000',
        ]);

        $period = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $period = SalaryPeriod::create($data + [
                    'batch_reference' => $this->nextReference(),
                    'status' => SalaryPeriod::STATUS_DRAFT,
                    'created_by_user_id' => $request->user()->id,
                ]);
                break;
            } catch (\Illuminate\Database\QueryException $e) {
                if ((string) $e->getCode() !== '23000' || $attempt === 2) {
                    throw $e;
                }
                $period = null;
            }
        }

        abort_if(! $period, 500, 'Unable to generate a unique salary batch reference.');

        log_activity(__('Salary period created.'), ['batch_reference' => $period->batch_reference], $period, 'Salary');

        \App\Services\SalaryNotifier::fire('period_created', $period, $request->user(), __('Salary Period Created'), 'Salary period ' . $period->label . ' (' . $period->batch_reference . ') was created by ' . $request->user()->name . '.', ['period' => $period->label, 'batch' => $period->batch_reference]);

        return redirect()->route('salary.periods.show', $period->id)->with('message', __('Salary period created.'));
    }

    public function show(Request $request, $id)
    {
        $this->authorize('read-salary-periods');

        $period = SalaryPeriod::findOrFail($id);
        $records = $period->records()->with(['staff:id,name,staff_number,position,photo', 'items', 'payments' => fn ($q) => $q->orderByDesc('payment_date')])->get();

        return Inertia::render('Salary/Periods/Show', [
            'period' => $this->presentDetail($period),
            'records' => $records->map(fn (SalaryRecord $r) => $this->presentRecord($r))->values(),
            'can' => [
                'prepare' => $request->user()->can('create-salary-periods') || $request->user()->can('prepare-bulk-salary-periods'),
                'approve' => $request->user()->can('approve-salary-periods'),
                'pay' => $request->user()->can('pay-salary-periods'),
            ],
            'allowedTargetStatuses' => (new \App\Policies\SalaryPeriodPolicy())->allowedTargetStatuses($request->user(), $period),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorize('update-salary-periods');

        $period = SalaryPeriod::findOrFail($id);
        abort_unless(in_array($period->status, [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING], true), 422, 'Only a Draft/Preparing salary period can be deleted.');
        abort_if($period->records()->exists(), 422, 'Remove every salary record from this period before deleting it.');

        $period->delete();

        log_activity(__('Salary period deleted.'), ['label' => $period->label], $period, 'Salary Period');

        \App\Services\SalaryNotifier::fire('period_deleted', $period, $request->user(), __('Salary Period Deleted'), 'Salary period ' . $period->label . ' (' . $period->batch_reference . ') was deleted by ' . $request->user()->name . '.', ['period' => $period->label, 'batch' => $period->batch_reference]);

        return redirect()->route('salary.periods.index')->with('message', __('Salary period deleted.'));
    }

    public function submit(Request $request, $id)
    {
        $period = SalaryPeriod::findOrFail($id);
        $this->authorize('changeStatus', [$period, SalaryPeriod::STATUS_PENDING_APPROVAL]);

        DB::transaction(function () use ($id) {
            $period = SalaryPeriod::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($period->records()->doesntExist(), 422, 'Prepare at least one salary record before submitting this period.');

            $period->update([
                'status' => SalaryPeriod::STATUS_PENDING_APPROVAL,
                'prepared_by_user_id' => auth()->id(),
                'prepared_at' => now(),
            ]);
            log_activity(__('Salary period submitted for approval.'), ['batch_reference' => $period->batch_reference], $period, 'Salary');
        });

        $this->notify(SalaryNotificationRule::EVENT_SUBMITTED, SalaryPeriod::findOrFail($id), $request->user()->id);

        return back()->with('message', __('Salary period submitted for approval.'));
    }

    public function approve(Request $request, $id)
    {
        $period = SalaryPeriod::findOrFail($id);
        $this->authorize('changeStatus', [$period, SalaryPeriod::STATUS_APPROVED]);

        DB::transaction(function () use ($id) {
            $period = SalaryPeriod::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($period->status === SalaryPeriod::STATUS_PENDING_APPROVAL, 422, 'This period must be Pending Approval for this action.');

            $period->update([
                'status' => SalaryPeriod::STATUS_APPROVED,
                'approved_by_user_id' => auth()->id(),
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);
            log_activity(__('Salary period approved.'), ['batch_reference' => $period->batch_reference], $period, 'Salary');
        });

        $this->notify(SalaryNotificationRule::EVENT_APPROVED, SalaryPeriod::findOrFail($id), $request->user()->id);

        return back()->with('message', __('Salary period approved.'));
    }

    public function reject(Request $request, $id)
    {
        $period = SalaryPeriod::findOrFail($id);
        $this->authorize('changeStatus', [$period, SalaryPeriod::STATUS_REJECTED]);

        $data = $request->validate(['rejection_reason' => 'required|string|max:1000']);

        DB::transaction(function () use ($id, $data) {
            $period = SalaryPeriod::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($period->status === SalaryPeriod::STATUS_PENDING_APPROVAL, 422, 'This period must be Pending Approval for this action.');

            $period->update(['status' => SalaryPeriod::STATUS_REJECTED, 'rejection_reason' => $data['rejection_reason']]);

            // The employee was never actually paid this period, so its
            // advance/loan deductions (committed to their repayment ledgers
            // at preparation time, not payment time) must not count as
            // repaid, and its records must stop counting toward the next
            // period's opening outstanding balance — mirrors the exact
            // rollback SalaryPreparationController::destroy() already does
            // for a single removed record, applied here to every record in
            // the rejected period. openingOutstandingBalance() already
            // excludes Rejected records; nothing was ever setting that
            // status, so this is what makes that filter actually work.
            $this->voidUnpaidRecords($period);

            log_activity(__('Salary period rejected.'), ['batch_reference' => $period->batch_reference, 'reason' => $data['rejection_reason']], $period, 'Salary');
        });

        $this->notify(SalaryNotificationRule::EVENT_REJECTED, SalaryPeriod::findOrFail($id), $request->user()->id);

        return back()->with('message', __('Salary period rejected.'));
    }

    /**
     * The policy/UI already promised an Approved/Partially Paid period can
     * be cancelled (allowedTargetStatuses(), the Super Admin carve-out in
     * AuthServiceProvider) but no route/controller ever implemented it.
     */
    public function cancel(Request $request, $id)
    {
        $period = SalaryPeriod::findOrFail($id);
        $this->authorize('changeStatus', [$period, SalaryPeriod::STATUS_CANCELLED]);

        $data = $request->validate(['cancellation_reason' => 'required|string|max:1000']);

        DB::transaction(function () use ($id, $data) {
            $period = SalaryPeriod::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($period->status, [SalaryPeriod::STATUS_APPROVED, SalaryPeriod::STATUS_PARTIALLY_PAID], true), 422, 'This period must be Approved or Partially Paid for this action.');

            $period->update(['status' => SalaryPeriod::STATUS_CANCELLED, 'rejection_reason' => $data['cancellation_reason']]);

            $this->voidUnpaidRecords($period);

            log_activity(__('Salary period cancelled.'), ['batch_reference' => $period->batch_reference, 'reason' => $data['cancellation_reason']], $period, 'Salary');
        });

        $period = SalaryPeriod::find($id);
        \App\Services\SalaryNotifier::fire('period_cancelled', $period, $request->user(), __('Salary Period Cancelled'), 'Salary period ' . $period->label . ' (' . $period->batch_reference . ') was cancelled by ' . $request->user()->name . '. Reason: ' . $data['cancellation_reason'], ['period' => $period->label, 'batch' => $period->batch_reference], null, ['remarks' => $data['cancellation_reason']]);

        return back()->with('message', __('Salary period cancelled.'));
    }

    /**
     * Reverses every unpaid record's committed advance/loan deductions and
     * marks it Rejected, so it stops counting toward the next period's
     * opening balance (shared by reject() and cancel()). A record that
     * already received a real payment is left completely untouched — money
     * already moved must never be undone by a status change alone.
     */
    private function voidUnpaidRecords(SalaryPeriod $period): void
    {
        $period->records()->get()->each(function (SalaryRecord $record) {
            if ($record->totalPaid() > 0) {
                return;
            }

            SalaryAdvanceRepayment::where('salary_record_id', $record->id)->get()->each(function ($r) {
                $advance = $r->advance;
                $r->delete();
                if ($advance && $advance->status === SalaryAdvance::STATUS_COMPLETED) {
                    $advance->update(['status' => SalaryAdvance::STATUS_ACTIVE]);
                }
            });
            SalaryLoanRepayment::where('salary_record_id', $record->id)->get()->each(function ($r) {
                $loan = $r->loan;
                $r->delete();
                if ($loan && $loan->status === SalaryLoan::STATUS_COMPLETED) {
                    $loan->update(['status' => SalaryLoan::STATUS_ACTIVE]);
                }
            });
            $record->update(['status' => SalaryRecord::STATUS_REJECTED]);
        });
    }

    private function notify(string $eventKey, SalaryPeriod $period, int $actingUserId): void
    {
        // Salary workflow page (Who Gets Notified / Messages): the starting values are the old behaviour
        $actor = \App\Models\User::find($actingUserId);
        $label = \App\Support\Workflow\WorkflowModules::get('salary')['events'][$eventKey]['label'] ?? __('Salary Update');
        \App\Services\Workflow\WorkflowMessenger::dispatch('salary', $eventKey, $period, $actor, [
            'account_id' => $actor?->account_id ?? 1, 'title' => $label,
            'message' => $period->label . ' (' . $period->batch_reference . ') - ' . strtolower(str_replace('_', ' ', $eventKey)) . '.',
            'remarks' => $period->rejection_reason,
        ]);
    }

    private function nextReference(): string
    {
        $prefix = 'SAL-BATCH-' . now()->format('Y-m') . '-';
        $prefixLength = strlen($prefix) + 1;
        $maxSuffix = (int) DB::table('salary_periods')
            ->selectRaw("MAX(CAST(SUBSTRING(batch_reference, {$prefixLength}) AS UNSIGNED)) as max_suffix")
            ->where('batch_reference', 'like', "{$prefix}%")
            ->value('max_suffix');

        return $prefix . str_pad((string) ($maxSuffix + 1), 4, '0', STR_PAD_LEFT);
    }

    private function presentSummary(SalaryPeriod $period): array
    {
        return [
            'id' => $period->id,
            'label' => $period->label,
            'batch_reference' => $period->batch_reference,
            'period_start' => optional($period->period_start)->format('Y-m-d'),
            'period_end' => optional($period->period_end)->format('Y-m-d'),
            'status' => $period->status,
            'records_count' => $period->records_count,
            'created_at' => optional($period->created_at)->format('Y-m-d'),
        ];
    }

    private function presentDetail(SalaryPeriod $period): array
    {
        return array_merge($this->presentSummary($period), [
            'notes' => $period->notes,
            'rejection_reason' => $period->rejection_reason,
            'prepared_by_name' => optional($period->preparedBy)->name,
            'prepared_at' => optional($period->prepared_at)->toDateTimeString(),
            'approved_by_name' => optional($period->approvedBy)->name,
            'approved_at' => optional($period->approved_at)->toDateTimeString(),
            'total_payable' => $period->totalPayable(),
            'total_paid' => $period->totalPaid(),
            'total_outstanding' => $period->totalOutstanding(),
        ]);
    }

    private function presentRecord(SalaryRecord $record): array
    {
        return [
            'id' => $record->id,
            'staff_id' => $record->staff_id,
            'staff_name' => optional($record->staff)->name,
            'staff_number' => optional($record->staff)->staff_number,
            'position' => optional($record->staff)->position,
            'photo_url' => $record->staff?->photo ? \Illuminate\Support\Facades\Storage::disk('assets')->url($record->staff->photo) : null,
            'basic_salary' => (float) $record->basic_salary,
            'total_earnings' => (float) $record->total_earnings,
            'total_deductions' => (float) $record->total_deductions,
            'advance_deduction' => (float) $record->advance_deduction,
            'loan_deduction' => (float) $record->loan_deduction,
            'net_salary' => (float) $record->net_salary,
            'opening_outstanding_balance' => (float) $record->opening_outstanding_balance,
            'current_payable' => (float) $record->current_payable,
            'total_paid' => $record->totalPaid(),
            'remaining_balance' => $record->remainingBalance(),
            'status' => $record->status,
            'payment_status' => $record->paymentStatus(),
            'items' => $record->relationLoaded('items') ? $record->items->map(fn ($i) => [
                'id' => $i->id, 'label' => $i->label, 'amount' => (float) $i->amount, 'type' => $i->type, 'source' => $i->source,
            ])->values() : [],
            'payments' => $record->relationLoaded('payments') ? $record->payments->map(fn ($p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'date' => optional($p->payment_date)->format('Y-m-d'),
                'method' => $p->payment_method, 'reference' => $p->payment_reference,
                'is_reversal' => (bool) $p->reverses_payment_id,
                'is_reversed' => \App\Models\SalaryPayment::where('reverses_payment_id', $p->id)->exists(),
            ])->values() : [],
        ];
    }
}
