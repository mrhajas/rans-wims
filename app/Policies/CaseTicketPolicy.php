<?php

namespace App\Policies;

use App\Models\CaseTicket;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Case Tracking is a flatter, permission-only workflow — unlike Labour/
 * Project Certificate/Material Request, it has no per-account configurable
 * "who does step N" assignment table, since the spec asks for granular
 * Roles-page permissions rather than a named approval chain.
 *
 * Every ability here — including the five "baseline" ones (viewing your own/
 * assigned cases, creating/submitting, editing while Draft/Returned,
 * commenting, and managing your own attachments) — is a real, softcoded
 * permission on the Roles page. They shipped once as a hardcoded `true` for
 * every user with no checkbox; that was reverted per a follow-up request so
 * Super Admin can grant or withhold them per role like everything else. Super
 * Admin/Admin bypass every check unconditionally, same as the rest of the app
 * (Super Admin via Gate::before, Admin via the explicit hasRole() checks
 * below).
 */
class CaseTicketPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin'])
            || $user->can('read-case-tracking')
            || $user->can('read-all-case-tracking');
    }

    /** read-case-tracking gates seeing your own/assigned cases at all; read-all-case-tracking widens that to every case. */
    public function view(User $user, CaseTicket $case): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin']) || $user->can('read-all-case-tracking')) {
            return true;
        }

        if (! $user->can('read-case-tracking')) {
            return false;
        }

        return (int) $case->created_by_user_id === (int) $user->id
            || (int) $case->assigned_to_user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('create-case-tracking');
    }

    /**
     * Draft/Returned only — the status lock applies to EVERYONE, including
     * Admin and update-any-case-tracking holders (mirrored again in
     * AuthServiceProvider's Gate::before carve-out, which is what actually
     * enforces it for Super Admin, since Gate::before never reaches this
     * method at all for that role). update-any/Admin only bypass the
     * OWNERSHIP requirement below, not the status window — a Closed/Resolved
     * case is meant to be a finalized record for every role, not just
     * regular users.
     */
    public function update(User $user, CaseTicket $case): bool
    {
        if (! in_array($case->status, CaseTicket::editableStatuses(), true)) {
            return false;
        }

        if ($user->hasRole(['Super Admin', 'Admin']) || $user->can('update-any-case-tracking')) {
            return true;
        }

        if (! $user->can('update-case-tracking')) {
            return false;
        }

        return (int) $case->created_by_user_id === (int) $user->id;
    }

    /** The Draft -> Submitted / Returned -> Submitted move — bundled with create-case-tracking (the spec's own "Create / Submit Case" pairing), not a status $user picks among, so kept separate from allowedTargetStatuses() exactly like LabourPolicy::submit(). */
    public function submit(User $user, CaseTicket $case): bool
    {
        if (! in_array($case->status, CaseTicket::editableStatuses(), true)) {
            return false;
        }

        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        return $user->can('create-case-tracking') && (int) $case->created_by_user_id === (int) $user->id;
    }

    /**
     * Draft-only, matching PaymentVoucher/LabourList's own delete rule —
     * deliberately NOT a baseline right like update/comment/attach: deleting
     * (even your own draft) stays behind an explicit grant, since it's the
     * one destructive action in the base set.
     *
     * Super Admin is the one exception to the draft-only rule: they can
     * delete a case at any status, not just Draft. The frontend's delete
     * button is already wrapped in SprConfirmPassword (re-verifies the
     * acting user's own password via the real password.confirm route)
     * regardless of who's deleting, so this doesn't skip that check — it
     * only lifts the status restriction for the one role trusted to clean
     * up a case that's already gone through the real workflow.
     */
    public function delete(User $user, CaseTicket $case): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        if ($case->status !== CaseTicket::STATUS_DRAFT) {
            return false;
        }

        return $user->hasRole('Admin')
            || ($user->can('delete-case-tracking') && (int) $case->created_by_user_id === (int) $user->id);
    }

    /** Requires comment-case-tracking on top of being able to see the case at all — including one you're merely assigned to, not just created. */
    public function comment(User $user, CaseTicket $case): bool
    {
        return $this->view($user, $case)
            && ($user->hasRole(['Super Admin', 'Admin']) || $user->can('comment-case-tracking'));
    }

    public function attach(User $user, CaseTicket $case): bool
    {
        return $this->view($user, $case)
            && ($user->hasRole(['Super Admin', 'Admin']) || $user->can('attach-case-tracking'));
    }

    /**
     * "Remove Own Attachment" from the spec — expressed as case ownership
     * rather than per-file uploader identity, since the shared, app-wide
     * Attachment table (vendor/tecdiary/laravel-attachments) has no
     * uploaded_by column and altering that shared table would risk every
     * other module that attaches files. In practice this means: the case's
     * own creator can remove attachments on their own case (gated by
     * attach-case-tracking), same as editing it; update-any-case-tracking/
     * Admin can remove on any case.
     */
    public function removeAttachment(User $user, CaseTicket $case): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin']) || $user->can('update-any-case-tracking')) {
            return true;
        }

        return $user->can('attach-case-tracking') && (int) $case->created_by_user_id === (int) $user->id;
    }

    public function assign(User $user, CaseTicket $case): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('assign-case-tracking');
    }

    /**
     * Every status this user could move the case to right now — mirrors
     * LabourPolicy::allowedTargetStatuses() exactly, gated by a plain
     * permission instead of a workflow-step assignment.
     */
    public function allowedTargetStatuses(User $user, CaseTicket $case): array
    {
        $canChangeStatus = $user->hasRole(['Super Admin', 'Admin']) || $user->can('change-status-case-tracking');
        $canResolve = $user->hasRole(['Super Admin', 'Admin']) || $user->can('resolve-case-tracking');
        $canReopen = $user->hasRole(['Super Admin', 'Admin']) || $user->can('reopen-case-tracking');

        // Built as ONE list rather than early-returning per branch — a user
        // who holds both change-status and reopen must see BOTH the normal
        // forward move (e.g. Resolved -> Closed) AND the reopen option on
        // the same Resolved/Closed case; an early return from the
        // change-status branch would silently hide the reopen option for
        // exactly the users (Super Admin/Admin) who hold every permission.
        $targets = [];

        if ($canChangeStatus) {
            if ($case->status === CaseTicket::STATUS_SUBMITTED) {
                $targets = [CaseTicket::STATUS_IN_REVIEW, CaseTicket::STATUS_RETURNED];
            } elseif ($case->status === CaseTicket::STATUS_IN_REVIEW) {
                $targets = [CaseTicket::STATUS_IN_PROGRESS, CaseTicket::STATUS_RETURNED];
                if ($canResolve) {
                    $targets[] = CaseTicket::STATUS_RESOLVED;
                }
            } elseif ($case->status === CaseTicket::STATUS_IN_PROGRESS) {
                $targets = [CaseTicket::STATUS_RETURNED];
                if ($canResolve) {
                    $targets[] = CaseTicket::STATUS_RESOLVED;
                }
            } elseif ($case->status === CaseTicket::STATUS_RESOLVED) {
                $targets = [CaseTicket::STATUS_CLOSED];
            }
        }

        if ($canReopen && in_array($case->status, [CaseTicket::STATUS_RESOLVED, CaseTicket::STATUS_CLOSED], true)) {
            $targets[] = CaseTicket::STATUS_IN_REVIEW;
        }

        return array_values(array_unique($targets));
    }

    public function changeStatus(User $user, CaseTicket $case, ?string $targetStatus = null): bool
    {
        return in_array($targetStatus, $this->allowedTargetStatuses($user, $case), true);
    }

    public function viewAnalytics(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('view-analytics-case-tracking');
    }

    public function export(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('export-case-tracking');
    }

    public function print(User $user, CaseTicket $case): bool
    {
        return $this->view($user, $case) && ($user->hasRole(['Super Admin', 'Admin']) || $user->can('print-case-tracking'));
    }

    public function manageSettings(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin']) || $user->can('manage-case-settings');
    }
}
