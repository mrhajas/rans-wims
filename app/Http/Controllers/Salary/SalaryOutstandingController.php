<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Models\SalaryOutstandingAdjustment;
use App\Models\SalaryRecord;
use App\Services\SalaryCalculator;
use Illuminate\Http\Request;

class SalaryOutstandingController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function store(Request $request, $recordId, SalaryCalculator $calculator)
    {
        $this->authorize('adjust-salary-periods');

        $data = $request->validate([
            'type' => 'required|in:' . implode(',', [
                SalaryOutstandingAdjustment::TYPE_WAIVER,
                SalaryOutstandingAdjustment::TYPE_CORRECTION,
                SalaryOutstandingAdjustment::TYPE_SETTLEMENT,
            ]),
            'amount' => 'required|numeric',
            'reason' => 'required|string|max:1000',
        ]);

        $record = SalaryRecord::findOrFail($recordId);

        // Waivers/settlements reduce what's owed — never allow one to flip
        // the opening balance negative, that would mean the company owes
        // the employee, a different (unsupported) scenario.
        $signedAmount = in_array($data['type'], [SalaryOutstandingAdjustment::TYPE_WAIVER, SalaryOutstandingAdjustment::TYPE_SETTLEMENT], true)
            ? -abs($data['amount'])
            : $data['amount'];
        abort_if((float) $record->opening_outstanding_balance + $signedAmount < 0, 422, 'This adjustment would make the outstanding balance negative.');

        $calculator->recordAdjustment($record, $data['type'], $signedAmount, $data['reason'], $request->user()->id);

        log_activity(__('Salary outstanding balance adjustment recorded.'), ['type' => $data['type'], 'amount' => $signedAmount, 'reason' => $data['reason']], $record, 'Salary');

        $record->loadMissing(['period', 'staff']);
        \App\Services\SalaryNotifier::fire('record_changed', $record, $request->user(), __('Salary Record Changed'), 'An outstanding balance adjustment (' . $data['type'] . ') was recorded on the salary of ' . optional($record->staff)->name . ' (' . optional($record->period)->label . ') by ' . $request->user()->name . '.', ['period' => optional($record->period)->label, 'batch' => optional($record->period)->batch_reference], null, ['remarks' => $data['reason']]);

        return back()->with('message', __('Adjustment recorded.'));
    }
}
