<?php

namespace App\Services\Signatories\Documents;

use App\Models\FundAllocation;
use App\Models\FundAllocationItemLine;
use Illuminate\Support\Carbon;

/**
 * Funds Allocation sheet (the sheet itself; the blank template sheet is not part of this).
 *
 * Real storage (FundAllocationController):
 *  - prepared  prepared_by_user_id (set on creation, never changed) + the time the preparer handed the sheet over = submitted_at
 *              (set by performSubmit(), kept through a rejection). A sheet that has never been submitted (Draft, or reopened to Draft which
 *              nulls submitted_at) has no sign-off time yet, so created_at is given instead and the note says so. GOOD.
 *  - approved  approved_by_user_id + approved_at (approve(); reopen() nulls both, so history shows the current round only). GOOD.
 *  - paid      The person who actually ticked the LAST payment: completed_by_user_id + completed_at, written by refreshProgress() when every
 *              payment line is ticked (the sheet moves to Completed). assigned_to_user_id is only the payer who was ASSIGNED and is not used as
 *              the actor. Cross-check: fund_allocation_item_lines.paid_by_user_id / paid_at hold who ticked each line (cleared when un-ticked).
 *              GOOD when one person ticked every line; PARTIAL when several people ticked lines (the sheet then names the person who
 *              completed it), or when completed_by_user_id is missing (older rows: the latest ticked line is used, else the assignee, both
 *              flagged as partial).
 *  - cleared   cleared_by_user_id + cleared_at (clear(); nulled again when a Super Admin reopens a Cleared sheet). GOOD.
 *
 * Stale data guard (status order Draft < Pending Approval < Approved < In Progress < Completed < Cleared): an action is reported only while
 * the status is at or past its stage. Rejected and Cancelled sheets show no approval, payment or clearing (cancel() keeps the old stamps,
 * which no longer stand); preparation always stands.
 *
 * State: Draft = draft; Completed and Cleared = final (every payment is ticked: the three printed sign-offs have all happened;
 * clearing only locks the sheet); everything else (Pending Approval, Approved, In Progress, Rejected, Cancelled) = in progress.
 */
final class FundAllocationDocument implements SignableDocument
{
    private const RANK = [
        FundAllocation::STATUS_DRAFT => 0, FundAllocation::STATUS_PENDING => 1, FundAllocation::STATUS_APPROVED => 2,
        FundAllocation::STATUS_IN_PROGRESS => 3, FundAllocation::STATUS_COMPLETED => 4, FundAllocation::STATUS_CLEARED => 5,
    ];

    public static function key(): string
    {
        return 'fund_allocation';
    }

    public static function label(): string
    {
        return 'Funds Allocation';
    }

    public static function actions(): array
    {
        return ['prepared' => 'Prepared (submitted for approval)', 'approved' => 'Approved', 'paid' => 'Paid (last payment ticked)', 'cleared' => 'Cleared (closed and cleared)'];
    }

    public function find(int $id): ?object
    {
        return FundAllocation::query()->withoutGlobalScope('workflow_visibility')->find($id);
    }

    public function state(object $record): string
    {
        $rank = self::RANK[$record->status] ?? -1;

        return match (true) {
            $record->status === FundAllocation::STATUS_DRAFT => 'draft',
            $rank >= 4 => 'final',
            default => 'in_progress',
        };
    }

    public function history(object $record): array
    {
        $out = [];
        $rank = self::RANK[$record->status] ?? -1; // Rejected / Cancelled = -1

        // prepared: the preparer; the time is the hand-over (submitted_at), created_at only for a sheet that was never submitted
        if ($record->submitted_at) {
            $out['prepared'] = $this->entry($record->prepared_by_user_id, $record->submitted_at, 'The person who prepared the sheet is not recorded.');
        } else {
            $e = $this->entry($record->prepared_by_user_id, $record->created_at, 'The person who prepared the sheet is not recorded.');
            $e['note'] = $e['note'] ?: 'The sheet has not been submitted for approval; the time shown is when it was created.';
            $out['prepared'] = $e;
        }

        if ($rank >= 2) {
            $out['approved'] = $this->entry($record->approved_by_user_id, $record->approved_at, 'The approval is not fully recorded on this sheet.');
        }
        if ($rank >= 4) {
            $out['paid'] = $this->paid($record);
        }
        if ($rank >= 5) {
            $out['cleared'] = $this->entry($record->cleared_by_user_id, $record->cleared_at, 'The clearing is not fully recorded on this sheet.');
        }

        return $out;
    }

    private function paid(object $record): array
    {
        $lines = FundAllocationItemLine::query()->where('is_paid', true)
            ->whereHas('item', fn ($q) => $q->where('fund_allocation_id', $record->id))->orderBy('paid_at')->orderBy('id')->get(['paid_by_user_id', 'paid_at']);
        $payers = $lines->pluck('paid_by_user_id')->filter()->unique()->values();
        $several = $payers->count() > 1 ? 'Payments on this sheet were ticked by several people; the person who ticked the last one is named.' : null;

        if ($record->completed_by_user_id) {
            $e = $this->entry($record->completed_by_user_id, $record->completed_at, 'The completion time is not recorded.');
            if ($several) {
                $e['quality'] = 'partial';
                $e['note'] = trim(($e['note'] ? $e['note'] . ' ' : '') . $several);
            }

            return $e;
        }

        // older rows: nobody stored on the sheet itself who completed it
        $last = $lines->last();
        if ($last && $last->paid_by_user_id) {
            return ['user_id' => (int) $last->paid_by_user_id, 'at' => $record->completed_at ?: $last->paid_at, 'name' => null, 'quality' => 'partial',
                'note' => 'The sheet does not store who completed it; the person who ticked the latest payment line is shown.' . ($several ? ' ' . $several : '')];
        }

        return ['user_id' => $record->assigned_to_user_id ? (int) $record->assigned_to_user_id : null, 'at' => $record->completed_at, 'name' => null, 'quality' => 'partial',
            'note' => 'Nobody is recorded as having ticked the payments; only the assigned payer is shown.'];
    }

    private function entry($userId, $at, string $note): array
    {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : null);
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    public static function defaultMappings(): array
    {
        // Printed labels: Prepared by, Approved by, Paid by. "Cleared By" was never printed, so it only appears once the sheet really was closed and cleared.
        return [
            ['role' => 'prepared_by', 'action' => 'prepared', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'paid_by', 'action' => 'paid', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
            ['role' => 'cleared_by', 'action' => 'cleared', 'slot' => 4, 'required' => false, 'show_when' => 'after'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
