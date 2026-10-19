<?php

namespace App\Http\Controllers\ComplianceManagement;

use App\Http\Controllers\Controller;
use App\Models\ComplianceHistory;
use App\Models\ComplianceRecord;
use App\Models\ComplianceRenewalHistory;
use App\Services\ComplianceNotifier;
use Illuminate\Http\Request;

class ComplianceRenewalController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function start(Request $request, ComplianceRecord $compliance_record)
    {
        $this->authorize('start-compliance-renewal');

        abort_if($compliance_record->activeRenewal, 422, 'A renewal is already in progress for this record.');

        ComplianceRenewalHistory::create([
            'compliance_record_id' => $compliance_record->id,
            'previous_issue_date' => $compliance_record->issue_date,
            'previous_expiry_date' => $compliance_record->expiry_date,
            'renewal_started_at' => now(),
            'status' => 'Renewal in Progress',
        ]);

        $compliance_record->update(['status' => 'Renewal in Progress']);
        ComplianceHistory::log($compliance_record, 'Renewal Started', __('Renewal started.'));
        ComplianceNotifier::fire('renewal_started', $compliance_record, __('Renewal Started'), $compliance_record->title . ' (' . $compliance_record->compliance_number . '): renewal started by ' . $request->user()->name . '.');

        return back()->with('message', __('Renewal started.'));
    }

    public function submit(Request $request, ComplianceRecord $compliance_record)
    {
        $this->authorize('start-compliance-renewal');

        $data = $request->validate([
            'application_number' => 'nullable|string|max:190',
            'renewal_fee' => 'nullable|numeric',
            'expected_completion_date' => 'nullable|date',
        ]);

        $renewal = $compliance_record->activeRenewal;
        abort_unless($renewal, 422, 'No renewal is in progress for this record.');
        abort_unless($renewal->status === 'Renewal in Progress', 422, 'This renewal has already been submitted.');

        $renewal->update($data + ['renewal_submitted_at' => now(), 'status' => 'Submitted']);
        $compliance_record->update(['status' => 'Submitted']);
        ComplianceHistory::log($compliance_record, 'Submitted', __('Renewal application submitted.'));
        ComplianceNotifier::fire('renewal_submitted', $compliance_record, __('Renewal Submitted'), $compliance_record->title . ' (' . $compliance_record->compliance_number . '): renewal application submitted and waiting for approval.');

        return back()->with('message', __('Renewal submitted.'));
    }

    public function approve(Request $request, ComplianceRecord $compliance_record)
    {
        $this->authorize('approve-compliance-renewal');

        $renewal = $compliance_record->activeRenewal;
        abort_unless($renewal, 422, 'No renewal is in progress for this record.');
        abort_unless($renewal->status === 'Submitted', 422, 'This renewal must be submitted before it can be approved.');

        $renewal->update(['status' => 'Pending Approval', 'approved_by_user_id' => $request->user()->id]);
        $compliance_record->update(['status' => 'Pending Approval']);
        ComplianceHistory::log($compliance_record, 'Approved', __('Renewal forwarded for final sign-off.'));
        ComplianceNotifier::fire('renewal_approved', $compliance_record, __('Renewal Approved'), $compliance_record->title . ' (' . $compliance_record->compliance_number . '): renewal approved and waiting for final sign-off.');

        return back()->with('message', __('Renewal approved for completion.'));
    }

    public function complete(Request $request, ComplianceRecord $compliance_record)
    {
        $this->authorize('complete-compliance-renewal');

        $data = $request->validate([
            'new_issue_date' => 'required|date',
            'new_expiry_date' => 'required|date|after_or_equal:new_issue_date',
            'notes' => 'nullable|string',
        ]);

        $renewal = $compliance_record->activeRenewal;
        abort_unless($renewal, 422, 'No renewal is in progress for this record.');
        abort_unless($renewal->status === 'Pending Approval', 422, 'This renewal must be approved before it can be completed.');

        $renewal->update($data + [
            'renewed_at' => now(),
            'completed_by_user_id' => $request->user()->id,
            'status' => 'Renewed',
        ]);

        // New cycle: dates roll forward, status resets to Active, and — since
        // reminder/duplication dedupe is scoped by renewal_history_id — a
        // fresh renewal_history row means every reminder interval can fire
        // again even if the same interval already fired pre-renewal.
        $compliance_record->update([
            'issue_date' => $data['new_issue_date'],
            'expiry_date' => $data['new_expiry_date'],
            'status' => 'Active',
        ]);
        $compliance_record->recalculateStatusAndPriority();

        ComplianceHistory::log($compliance_record, 'Renewal Completed', __('Renewed, new expiry :date.', ['date' => $data['new_expiry_date']]));
        ComplianceNotifier::fire('renewal_completed', $compliance_record, __('Renewal Completed'), $compliance_record->title . ' (' . $compliance_record->compliance_number . ') was renewed, new expiry ' . $data['new_expiry_date'] . '.', $data['notes'] ?? null);

        return back()->with('message', __('Renewal completed.'));
    }

    public function reject(Request $request, ComplianceRecord $compliance_record)
    {
        $this->authorize('approve-compliance-renewal');

        $data = $request->validate(['notes' => 'nullable|string']);

        $renewal = $compliance_record->activeRenewal;
        abort_unless($renewal, 422, 'No renewal is in progress for this record.');

        $renewal->update($data + ['status' => 'Rejected']);
        $compliance_record->update(['status' => $renewal->previous_expiry_date && now()->gt($renewal->previous_expiry_date) ? 'Expired' : 'Active']);
        $compliance_record->recalculateStatusAndPriority();

        ComplianceHistory::log($compliance_record, 'Rejected', __('Renewal rejected.'));
        ComplianceNotifier::fire('renewal_rejected', $compliance_record, __('Renewal Rejected'), $compliance_record->title . ' (' . $compliance_record->compliance_number . '): renewal rejected.', $data['notes'] ?? null);

        return back()->with('message', __('Renewal rejected.'));
    }

    public function cancel(Request $request, ComplianceRecord $compliance_record)
    {
        $this->authorize('start-compliance-renewal');

        $renewal = $compliance_record->activeRenewal;
        abort_unless($renewal, 422, 'No renewal is in progress for this record.');

        $renewal->update(['status' => 'Cancelled']);
        $compliance_record->update(['status' => $renewal->previous_expiry_date && now()->gt($renewal->previous_expiry_date) ? 'Expired' : 'Active']);
        $compliance_record->recalculateStatusAndPriority();

        ComplianceHistory::log($compliance_record, 'Cancelled', __('Renewal cancelled.'));
        ComplianceNotifier::fire('renewal_cancelled', $compliance_record, __('Renewal Cancelled'), $compliance_record->title . ' (' . $compliance_record->compliance_number . '): renewal cancelled by ' . $request->user()->name . '.');

        return back()->with('message', __('Renewal cancelled.'));
    }
}
