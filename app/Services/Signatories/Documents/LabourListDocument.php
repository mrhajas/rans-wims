<?php

namespace App\Services\Signatories\Documents;

use App\Models\LabourList;
use Illuminate\Support\Carbon;

/**
 * Labour List.
 *
 * Real storage (LabourController):
 *  - created    created_by_user_id + created_at                 (GOOD)
 *  - submitted  submitted_by_user_id + submitted_at             (GOOD; submit() rewrites both on every (re)submission)
 *  - cleared    cleared_by_user_id + cleared_at                 (GOOD; updateStatus() to Cleared, by the Project Manager)
 *  - approved   approved_by_user_id + approved_at               (GOOD; updateStatus() to Approved)
 *
 * Stale data guard: Return (reject) only changes the status, it never clears the stamps, and a revised list keeps its old cleared_* and
 * approved_* until the next round overwrites them. Status order: Draft < Submitted < Cleared < Approved < Payment Voucher Created, and
 * Returned stands for "back to the site engineer": a Returned list shows none of the review stages (the submission has to be redone).
 * A stage is reported only when the current status is at or past it. The policy allows no stage to be skipped, so a status past a stage with
 * no stamp is reported as 'partial' (the action happened but who/when was not stored) instead of being invented.
 *
 * State: Draft and Returned = draft (the list is being written or corrected by the site engineer); Submitted and Cleared = in progress;
 * Approved and Payment Voucher Created = final.
 */
final class LabourListDocument implements SignableDocument
{
    private const RANK = [
        LabourList::STATUS_DRAFT => 0, LabourList::STATUS_SUBMITTED => 1, LabourList::STATUS_CLEARED => 2,
        LabourList::STATUS_APPROVED => 3, LabourList::STATUS_PAYMENT_VOUCHER_CREATED => 4,
    ];

    public static function key(): string
    {
        return 'labour_list';
    }

    public static function label(): string
    {
        return 'Labour List';
    }

    public static function actions(): array
    {
        return ['created' => 'Created', 'submitted' => 'Submitted (Site Engineer)', 'cleared' => 'Cleared (Project Manager)', 'approved' => 'Approved (Super Admin)'];
    }

    public function find(int $id): ?object
    {
        return LabourList::query()->withoutGlobalScope('workflow_visibility')->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->status) {
            LabourList::STATUS_DRAFT, LabourList::STATUS_RETURNED => 'draft',
            LabourList::STATUS_APPROVED, LabourList::STATUS_PAYMENT_VOUCHER_CREATED => 'final',
            default => 'in_progress',
        };
    }

    public function history(object $record): array
    {
        $out = ['created' => $this->entry($record->created_by_user_id, $record->created_at, 'The person who created the list is not recorded.')];
        $rank = self::RANK[$record->status] ?? -1; // Returned (or anything unknown) = no review stage stands

        if ($rank >= 1) {
            $out['submitted'] = $this->entry($record->submitted_by_user_id, $record->submitted_at, 'The submission is not fully recorded on this list.');
        }
        if ($rank >= 2) {
            $out['cleared'] = $this->entry($record->cleared_by_user_id, $record->cleared_at, 'The clearing is not fully recorded on this list.');
        }
        if ($rank >= 3) {
            $out['approved'] = $this->entry($record->approved_by_user_id, $record->approved_at, 'The approval is not fully recorded on this list.');
        }

        return $out;
    }

    private function entry($userId, $at, string $note): array
    {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : null);
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    public static function defaultMappings(): array
    {
        // Printed labels: Submitted By (Site Engineer), Cleared By (Project Manager), Approved By (Super Admin).
        return [
            ['role' => 'submitted_by', 'action' => 'submitted', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'cleared_by', 'action' => 'cleared', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
