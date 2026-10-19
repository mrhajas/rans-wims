<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\SalaryPeriod;
use App\Models\SalaryRecord;
use App\Models\SalaryRecordItem;
use App\Models\Staff;
use App\Services\SalaryCalculator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalaryPreparationController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function individual(Request $request)
    {
        $this->authorize('create-salary-periods');

        return Inertia::render('Salary/Preparation/Individual', [
            'periods' => SalaryPeriod::whereIn('status', [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING])
                ->orderByDesc('period_start')->get(['id', 'label', 'batch_reference']),
            'staffs' => Staff::payroll()->orderBy('name')->get(['id', 'name', 'staff_number', 'position', 'basic_salary']),
        ]);
    }

    /** The first preparation of a period: Salary workflow page ("Salary Prepared"). */
    private function alertPrepared(SalaryPeriod $period, Request $request): void
    {
        \App\Services\Workflow\WorkflowMessenger::dispatch('salary', 'prepared', $period, $request->user(), [
            'account_id' => $request->user()->account_id ?? 1, 'title' => __('Salary Prepared'),
            'message' => $period->label . ' (' . $period->batch_reference . ') - prepared.',
        ]);
    }

    public function storeIndividual(Request $request, SalaryCalculator $calculator)
    {
        $this->authorize('create-salary-periods');

        $data = $request->validate([
            'salary_period_id' => 'required|exists:salary_periods,id',
            'staff_id' => 'required|exists:staffs,id',
        ]);

        $period = SalaryPeriod::findOrFail($data['salary_period_id']);
        abort_unless(in_array($period->status, [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING], true), 422, 'This salary period can no longer be prepared.');

        $staff = Staff::findOrFail($data['staff_id']);

        try {
            $record = $calculator->prepareForStaff($period, $staff, $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($period->status === SalaryPeriod::STATUS_DRAFT) {
            $period->update(['status' => SalaryPeriod::STATUS_PREPARING]);
            $this->alertPrepared($period, $request);
        }

        log_activity(__('Salary prepared for :name.', ['name' => $staff->name]), ['batch_reference' => $period->batch_reference, 'net_salary' => $record->net_salary], $period, 'Salary');

        return redirect()->route('salary.periods.show', $period->id)->with('message', __('Salary prepared for :name.', ['name' => $staff->name]));
    }

    public function bulk(Request $request)
    {
        $this->authorize('prepare-bulk-salary-periods');

        if (! $request->filled('salary_period_id')) {
            return redirect()->route('salary.periods.index')->with('error', __('Open a salary period first, then choose Prepare Bulk.'));
        }

        $period = SalaryPeriod::findOrFail($request->query('salary_period_id'));
        $preparedStaffIds = $period->records()->pluck('staff_id');

        return Inertia::render('Salary/Preparation/Bulk', [
            'period' => ['id' => $period->id, 'label' => $period->label, 'batch_reference' => $period->batch_reference, 'status' => $period->status],
            'eligibleStaff' => Staff::payroll()
                ->whereNotIn('id', $preparedStaffIds)
                ->orderBy('name')
                ->get(['id', 'name', 'staff_number', 'position', 'basic_salary', 'allowances', 'deductions']),
        ]);
    }

    public function storeBulk(Request $request, SalaryCalculator $calculator)
    {
        $this->authorize('prepare-bulk-salary-periods');

        $data = $request->validate([
            'salary_period_id' => 'required|exists:salary_periods,id',
            'staff_ids' => 'required|array|min:1',
            'staff_ids.*' => 'integer|exists:staffs,id',
        ]);

        $period = SalaryPeriod::findOrFail($data['salary_period_id']);
        abort_unless(in_array($period->status, [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING], true), 422, 'This salary period can no longer be prepared.');

        $prepared = 0;
        $skipped = [];

        foreach (Staff::whereIn('id', $data['staff_ids'])->get() as $staff) {
            try {
                $calculator->prepareForStaff($period, $staff, $request->user()->id);
                $prepared++;
            } catch (\RuntimeException $e) {
                $skipped[] = $staff->name;
            }
        }

        if ($period->status === SalaryPeriod::STATUS_DRAFT && $prepared > 0) {
            $period->update(['status' => SalaryPeriod::STATUS_PREPARING]);
            $this->alertPrepared($period, $request);
        }

        log_activity(__('Bulk salary prepared for :count employee(s).', ['count' => $prepared]), ['batch_reference' => $period->batch_reference, 'skipped' => $skipped], $period, 'Salary');

        $message = __('Salary prepared for :count employee(s).', ['count' => $prepared]);
        if ($skipped) {
            $message .= ' ' . __('Skipped (already prepared): :names.', ['names' => implode(', ', $skipped)]);
        }

        return redirect()->route('salary.periods.show', $period->id)->with('message', $message);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorize('update-salary-periods');

        $record = SalaryRecord::findOrFail($id);
        abort_unless($record->status === SalaryRecord::STATUS_DRAFT, 422, 'Only a Draft salary record can be removed.');
        abort_unless(in_array($record->period->status, [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING], true), 422, 'This salary period can no longer be edited.');

        $periodId = $record->salary_period_id;
        $staffName = optional($record->staff)->name;

        // Repayment ledger rows (advance/loan deductions already committed
        // to this record) are removed too — the advance/loan status is
        // re-opened to Active if it had been auto-completed by this record.
        \App\Models\SalaryAdvanceRepayment::where('salary_record_id', $record->id)->get()->each(function ($r) {
            $advance = $r->advance;
            $r->delete();
            if ($advance && $advance->status === \App\Models\SalaryAdvance::STATUS_COMPLETED) {
                $advance->update(['status' => \App\Models\SalaryAdvance::STATUS_ACTIVE]);
            }
        });
        \App\Models\SalaryLoanRepayment::where('salary_record_id', $record->id)->get()->each(function ($r) {
            $loan = $r->loan;
            $r->delete();
            if ($loan && $loan->status === \App\Models\SalaryLoan::STATUS_COMPLETED) {
                $loan->update(['status' => \App\Models\SalaryLoan::STATUS_ACTIVE]);
            }
        });

        $record->delete();

        log_activity(__('Salary record removed for :name.', ['name' => $staffName]), [], $record->period, 'Salary');

        $removedPeriod = \App\Models\SalaryPeriod::find($periodId);
        \App\Services\SalaryNotifier::fire('record_removed', $removedPeriod, $request->user(), __('Salary Record Removed'), 'The salary record of ' . $staffName . ' was removed from ' . optional($removedPeriod)->label . ' by ' . $request->user()->name . '.', ['period' => optional($removedPeriod)->label, 'batch' => optional($removedPeriod)->batch_reference, 'employee' => (string) $staffName]);

        return back()->with('message', __('Salary record removed.'));
    }

    /** A one-off earning/deduction line — overtime, bonus, leave-without-pay, etc. — the SOURCE_MANUAL item type existed in the schema but nothing ever created one. */
    public function storeItem(Request $request, $recordId, SalaryCalculator $calculator)
    {
        $this->authorize('update-salary-periods');

        $record = SalaryRecord::with('period')->findOrFail($recordId);
        abort_unless(in_array($record->period->status, [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING], true), 422, 'This salary period can no longer be edited.');

        $data = $request->validate([
            'label' => 'required|string|max:190',
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:earning,deduction',
        ]);

        $calculator->addManualItem($record, $data['label'], (float) $data['amount'], $data['type']);

        log_activity(__(':label added for :name.', ['label' => $data['label'], 'name' => optional($record->staff)->name]), ['amount' => $data['amount'], 'type' => $data['type']], $record->period, 'Salary');

        \App\Services\SalaryNotifier::fire('record_changed', $record, $request->user(), __('Salary Record Changed'), $data['label'] . ' was added to the salary of ' . optional($record->staff)->name . ' (' . $record->period->label . ') by ' . $request->user()->name . '.', ['period' => $record->period->label, 'batch' => $record->period->batch_reference]);

        return back()->with('message', __('Item added.'));
    }

    public function destroyItem(Request $request, $recordId, $itemId, SalaryCalculator $calculator)
    {
        $this->authorize('update-salary-periods');

        $record = SalaryRecord::with('period')->findOrFail($recordId);
        abort_unless(in_array($record->period->status, [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING], true), 422, 'This salary period can no longer be edited.');

        $item = SalaryRecordItem::where('salary_record_id', $recordId)->findOrFail($itemId);
        $label = $item->label;

        $calculator->removeManualItem($item);

        log_activity(__(':label removed for :name.', ['label' => $label, 'name' => optional($record->staff)->name]), [], $record->period, 'Salary');

        \App\Services\SalaryNotifier::fire('record_changed', $record, $request->user(), __('Salary Record Changed'), $label . ' was removed from the salary of ' . optional($record->staff)->name . ' (' . $record->period->label . ') by ' . $request->user()->name . '.', ['period' => $record->period->label, 'batch' => $record->period->batch_reference]);

        return back()->with('message', __('Item removed.'));
    }
}
