<?php

namespace App\Providers;

use App\Models\CaseTicket;
use App\Models\LabourList;
use App\Models\MaterialReallocation;
use App\Models\MaterialRequest;
use App\Models\ProjectCertificate;
use App\Models\SalaryPeriod;
use App\Models\Task;
use App\Models\TransportRecord;
use App\Models\User;
use App\Models\WorkforceAgreement;
use App\Policies\CaseTicketPolicy;
use App\Policies\LabourPolicy;
use App\Policies\MaterialRequestPolicy;
use App\Policies\SalaryPeriodPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        'App\Models\Checkin'    => 'App\Policies\EditPolicy',
        'App\Models\Checkout'   => 'App\Policies\EditPolicy',
        'App\Models\Transfer'   => 'App\Policies\EditPolicy',
        'App\Models\Adjustment' => 'App\Policies\EditPolicy',
        'App\Models\IssueTransaction' => 'App\Policies\EditPolicy',
        'App\Models\MaterialRequest' => 'App\Policies\MaterialRequestPolicy',
        'App\Models\MaterialReallocation' => 'App\Policies\MaterialReallocationPolicy',
        'App\Models\DailySiteReport' => 'App\Policies\DailySiteReportPolicy',
        'App\Models\Notice' => 'App\Policies\NoticePolicy',
        'App\Models\LoginLog' => 'App\Policies\LoginLogPolicy',
        'App\Models\DownloadPrintLog' => 'App\Policies\DownloadPrintLogPolicy',
        'App\Models\WorkPackage' => 'App\Policies\WorkPackagePolicy',
        'App\Models\WorkPackageIssue' => 'App\Policies\WorkPackageIssuePolicy',
        'App\Models\ProjectCertificate' => 'App\Policies\ProjectCertificatePolicy',
        'App\Models\LabourList' => 'App\Policies\LabourPolicy',
        'App\Models\WorkforceAgreement' => 'App\Policies\WorkforceAgreementPolicy',
        'App\Models\CaseTicket' => 'App\Policies\CaseTicketPolicy',
        'App\Models\TransportRecord' => 'App\Policies\TransportRecordPolicy',
        'App\Models\Task' => 'App\Policies\TaskPolicy',
        'App\Models\SalaryPeriod' => 'App\Policies\SalaryPeriodPolicy',
        'App\Models\MaterialRequestReminder' => 'App\Policies\MaterialRequestReminderPolicy',
    ];

    public function boot()
    {
        $this->registerPolicies();

        Gate::before(function ($user, $ability, $arguments = []) {
            if ($user->hasRole('Super Admin')) {
                if (isset($arguments[0]) && $arguments[0] instanceof MaterialRequest) {
                    $materialRequest = $arguments[0];
                    // A cleared / completed request is locked for everybody, Super Admin included.
                    if (in_array($ability, ['update', 'delete'], true) && $materialRequest->isFinal()) {
                        return false;
                    }

                    // Editing rebuilds the item list, so it stops once Procurement has started (see the policy).
                    if ($ability === 'update' && ! (new MaterialRequestPolicy())->update($user, $materialRequest)) {
                        return false;
                    }

                    // Without this, Gate::before's blanket bypass let Super
                    // Admin/Admin jump a request to ANY status regardless of
                    // its current one (allowedTargetStatuses() used to return
                    // every status unconditionally for them) — a Draft/Pending
                    // request could be set straight to Issued or Closed, and a
                    // Rejected one silently re-approved. Delegates to the real
                    // policy method, same pattern as LabourList/CaseTicket below.
                    if ($ability === 'changeStatus'
                        && ! in_array($arguments[1] ?? null, (new MaterialRequestPolicy())->allowedTargetStatuses($user, $materialRequest), true)) {
                        return false;
                    }
                }

                // The certificate workflow (draft -> submitted -> approved ->
                // issued) has to hold for EVERY user, Super Admin included —
                // otherwise "you can't issue before it's reviewed and
                // approved" is only enforced for people who happen not to be
                // Super Admin, which defeats the point of having a workflow
                // at all. Without this carve-out, Gate::before's blanket
                // `return true` above would let Super Admin's `$user->can(...)`
                // checks skip ProjectCertificatePolicy's own status checks
                // entirely, same class of bug as the MaterialRequest case
                // above: it also silently shows every action button at once
                // in the UI, since Show.vue's `can.*` props come from these
                // same ability checks.
                if (isset($arguments[0]) && $arguments[0] instanceof ProjectCertificate) {
                    $certificate = $arguments[0];
                    $blocked = match ($ability) {
                        'update' => $certificate->status !== ProjectCertificate::STATUS_DRAFT,
                        'submit' => $certificate->status !== ProjectCertificate::STATUS_DRAFT,
                        'approve' => $certificate->status !== ProjectCertificate::STATUS_SUBMITTED,
                        'issue' => $certificate->status !== ProjectCertificate::STATUS_APPROVED,
                        'revise' => $certificate->status !== ProjectCertificate::STATUS_ISSUED,
                        'cancel' => in_array($certificate->status, [ProjectCertificate::STATUS_ISSUED, ProjectCertificate::STATUS_CANCELLED], true),
                        'recordPayment', 'editPayment' => $certificate->status !== ProjectCertificate::STATUS_ISSUED,
                        default => false,
                    };

                    if ($blocked) {
                        return false;
                    }
                }

                // Same class of carve-out as ProjectCertificate above —
                // without it, Gate::before's blanket bypass would let Super
                // Admin edit/delete a locked Labour List, or click "Create
                // Payment Voucher" a second time on one already converted,
                // defeating LabourPolicy's own status-gating and the
                // one-shot conversion guard entirely for that one role.
                if (isset($arguments[0]) && $arguments[0] instanceof LabourList) {
                    $labourList = $arguments[0];
                    $blocked = match ($ability) {
                        'update' => ! in_array($labourList->status, LabourList::editableStatuses(), true),
                        'delete' => $labourList->status !== LabourList::STATUS_DRAFT,
                        'submit' => ! in_array($labourList->status, LabourList::editableStatuses(), true),
                        'createPaymentVoucher' => $labourList->status !== LabourList::STATUS_APPROVED || ! empty($labourList->payment_voucher_batch_id),
                        // Without this, Gate::before answered `true` for
                        // `changeStatus` immediately and LabourPolicy::
                        // changeStatus()/allowedTargetStatuses() was never
                        // even consulted for Super Admin — letting them jump
                        // any status to any status (Draft straight to
                        // Approved, or re-approving an already-Approved
                        // list), with the "Approve for Payment" button's
                        // client-side :disabled guard in Labour/Show.vue the
                        // only thing standing in the way. This delegates to
                        // the real policy method instead of hand-duplicating
                        // its transition table here.
                        'changeStatus' => ! in_array($arguments[1] ?? null, (new LabourPolicy())->allowedTargetStatuses($user, $labourList), true),
                        default => false,
                    };

                    if ($blocked) {
                        return false;
                    }
                }

                // Same carve-out again — once a LabourListItem references an
                // agreement, its terms are locked; without this, Super Admin
                // could silently edit an agreed_amount that payments have
                // already been calculated and made against.
                if (isset($arguments[0]) && $arguments[0] instanceof WorkforceAgreement) {
                    $agreement = $arguments[0];
                    $blocked = match ($ability) {
                        'update' => $agreement->isLocked(),
                        'cancel' => $agreement->status !== WorkforceAgreement::STATUS_ACTIVE,
                        default => false,
                    };

                    if ($blocked) {
                        return false;
                    }
                }

                // Same class of carve-out as LabourList above — without it,
                // Super Admin's blanket bypass would skip
                // CaseTicketPolicy::allowedTargetStatuses() entirely for
                // changeStatus, letting them jump a case straight from
                // Submitted to Closed, edit/resubmit a case no longer in
                // Draft/Returned, etc. 'delete' is deliberately NOT in this
                // match — Super Admin is explicitly allowed to delete a case
                // at any status (CaseTicketPolicy::delete()'s own carve-out,
                // password-reconfirmed by the frontend regardless), unlike
                // update/submit/changeStatus which stay status-gated even
                // for them.
                if (isset($arguments[0]) && $arguments[0] instanceof CaseTicket) {
                    $case = $arguments[0];
                    $blocked = match ($ability) {
                        'update' => ! in_array($case->status, CaseTicket::editableStatuses(), true),
                        'submit' => ! in_array($case->status, CaseTicket::editableStatuses(), true),
                        'changeStatus' => ! in_array($arguments[1] ?? null, (new CaseTicketPolicy())->allowedTargetStatuses($user, $case), true),
                        default => false,
                    };

                    if ($blocked) {
                        return false;
                    }
                }

                // MaterialReallocationPolicy's own methods already pair each
                // Super Admin/Admin branch with the matching status check
                // (e.g. allocate() requires PM_CLEARANCE even for them) — but
                // Gate::before short-circuits before any Policy method ever
                // runs, so none of that logic was actually being consulted
                // for Super Admin. This mirrors those same conditions here,
                // the same pattern as every other workflow model above —
                // without it, Super Admin could e.g. call allocate() on a
                // Draft record, skipping submit()/clear() entirely.
                // Without this, Gate::before's blanket bypass would let Super
                // Admin create a Payment Voucher from a trip that's already
                // linked to one, or from a Cancelled trip — the exact
                // duplicate-payment/stale-record cases
                // TransportRecordPolicy::createPaymentVoucher() exists to
                // block for everyone else. Delegate to the real policy
                // method's status check, same carve-out pattern as
                // MaterialRequest/MaterialReallocation above.
                if (isset($arguments[0]) && $arguments[0] instanceof TransportRecord && $ability === 'createPaymentVoucher') {
                    $transportRecord = $arguments[0];
                    if ($transportRecord->payment_voucher_id !== null || $transportRecord->payment_status === TransportRecord::STATUS_CANCELLED) {
                        return false;
                    }
                }

                if (isset($arguments[0]) && $arguments[0] instanceof MaterialReallocation) {
                    $mrr = $arguments[0];
                    $blocked = match ($ability) {
                        'update', 'cancel' => in_array($mrr->status, MaterialReallocation::lockedStatuses(), true),
                        'submit' => ! in_array($mrr->status, [MaterialReallocation::STATUS_DRAFT, MaterialReallocation::STATUS_REQUEST_CHANGES], true),
                        'clear' => $mrr->status !== MaterialReallocation::STATUS_SUBMITTED,
                        'allocate' => $mrr->status !== MaterialReallocation::STATUS_PM_CLEARANCE,
                        'dispatch' => $mrr->status !== MaterialReallocation::STATUS_WAREHOUSE_ALLOCATION,
                        'receive' => $mrr->status !== MaterialReallocation::STATUS_DISPATCH_PREPARATION,
                        'finalApprove' => $mrr->status !== MaterialReallocation::STATUS_WAREHOUSE_RECEIVING,
                        'requestChanges', 'reject' => ! in_array($mrr->status, [MaterialReallocation::STATUS_SUBMITTED, MaterialReallocation::STATUS_PM_CLEARANCE], true),
                        default => false,
                    };

                    if ($blocked) {
                        return false;
                    }
                }

                // Same class of carve-out as MaterialRequest/CaseTicket above
                // — without it, Super Admin's blanket bypass would let them
                // jump a salary period straight to Approved/Paid skipping
                // the prepare->approve->pay workflow entirely, which
                // defeats the whole point of SalaryWorkflowStep-configurable
                // approval/payment roles for every other financial-integrity
                // rule the module depends on (see SalaryPeriodPolicy).
                if (isset($arguments[0]) && $arguments[0] instanceof SalaryPeriod) {
                    $salaryPeriod = $arguments[0];
                    $blocked = match ($ability) {
                        'update' => ! in_array($salaryPeriod->status, [SalaryPeriod::STATUS_DRAFT, SalaryPeriod::STATUS_PREPARING, SalaryPeriod::STATUS_REJECTED], true),
                        'changeStatus' => ! in_array($arguments[1] ?? null, (new SalaryPeriodPolicy())->allowedTargetStatuses($user, $salaryPeriod), true),
                        'pay' => ! in_array($salaryPeriod->status, [SalaryPeriod::STATUS_APPROVED, SalaryPeriod::STATUS_PARTIALLY_PAID], true),
                        default => false,
                    };

                    if ($blocked) {
                        return false;
                    }
                }

                // A Super Admin-created personal task marked private (see
                // TaskManagerController::store()) is exclusive to its
                // creator and current assignees — without this, Super
                // Admin's blanket bypass above would let every OTHER Super
                // Admin see/edit/delete/assign it too, defeating the whole
                // point of marking it private. Mirrors TaskPolicy's own
                // is_private check, which is never actually reached for a
                // Super Admin otherwise.
                if (isset($arguments[0]) && $arguments[0] instanceof Task) {
                    $task = $arguments[0];
                    $isOwnOrAssigned = (int) $task->created_by_user_id === (int) $user->id
                        || $task->assignees()->where('assignee_type', User::class)->where('assignee_id', $user->id)->exists();

                    if ($task->is_private && ! $isOwnOrAssigned) {
                        return false;
                    }
                }

                return true;
            }
        });
    }
}
