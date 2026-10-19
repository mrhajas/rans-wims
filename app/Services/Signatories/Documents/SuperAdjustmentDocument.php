<?php

namespace App\Services\Signatories\Documents;

use App\Models\SuperAdjustment;

/**
 * Super adjustment (stock count: Draft = waiting for approval / Approved / Rejected).
 *
 * Real records: user_id + created_at (the person who submitted the count; set at creation and never changed by edits), approved_by_user_id +
 * approved_at (+ approved_by_role), rejected_by_user_id + rejected_at. moveToStatus() clears both pairs before every status move, and this adapter
 * also ignores an approval stamp on a record that is not Approved.
 *
 * States: "final" = Approved. Draft (which here means submitted and waiting for a Super Admin) and Rejected are "in_progress", because the
 * submitter has already acted; a Rejected count can be re-approved.
 */
final class SuperAdjustmentDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'super_adjustment';
    }

    public static function label(): string
    {
        return 'Super adjustment';
    }

    public static function actions(): array
    {
        return [
            'submitted' => 'Submitted (the person who entered the count)',
            'first_approved' => 'Approved, phase 1 (the first Super Admin)',
            'approved' => 'Approved (the final approval: phase 2 when two approvals are needed)',
            'rejected' => 'Rejected',
        ];
    }

    public function find(int $id): ?object
    {
        return SuperAdjustment::query()->withoutGlobalScope('mine')->find($id);
    }

    public function state(object $record): string
    {
        return $record->status === SuperAdjustment::STATUS_APPROVED ? 'final' : 'in_progress';
    }

    public function history(object $record): array
    {
        $h = [];

        if ($record->user_id || $record->created_at) {
            $h['submitted'] = [
                'user_id' => $record->user_id ? (int) $record->user_id : null, 'at' => StockSigningGuard::at($record->created_at), 'name' => null,
                'quality' => $record->user_id && $record->created_at ? 'good' : 'partial',
                'note' => 'Time is when the count was first entered in WIMS (later edits are not stamped separately).',
            ];
        }

        // phase 1 of two: from the approval rows that still stand (set up on the Document Setup page like any other action)
        if (in_array($record->status, [SuperAdjustment::STATUS_AWAITING, SuperAdjustment::STATUS_APPROVED], true) && $record->exists) {
            $first = \App\Models\SuperAdjustmentApproval::where('super_adjustment_id', $record->id)->whereNull('voided_at')->where('phase', 1)->first();
            if ($first && (SuperAdjustment::requiredApprovals() === 2 || $record->status === SuperAdjustment::STATUS_AWAITING)) {
                $h['first_approved'] = ['user_id' => (int) $first->user_id, 'at' => StockSigningGuard::at($first->approved_at), 'name' => null, 'quality' => 'good', 'note' => null];
            }
        }

        if ($record->status === SuperAdjustment::STATUS_APPROVED) {
            $h['approved'] = [
                'user_id' => $record->approved_by_user_id ? (int) $record->approved_by_user_id : null, 'at' => StockSigningGuard::at($record->approved_at), 'name' => null,
                'quality' => $record->approved_by_user_id && $record->approved_at ? 'good' : 'partial',
                'note' => $record->approved_by_user_id && $record->approved_at ? null : 'The count is approved but who approved it, or when, was not stamped.',
            ];
        } elseif ($record->status === SuperAdjustment::STATUS_REJECTED) {
            $h['rejected'] = [
                'user_id' => $record->rejected_by_user_id ? (int) $record->rejected_by_user_id : null, 'at' => StockSigningGuard::at($record->rejected_at), 'name' => null,
                'quality' => $record->rejected_by_user_id ? 'good' : 'partial',
                'note' => $record->rejected_by_user_id ? null : 'The rejection has a time but the person who rejected it was not stamped.',
            ];
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        // Printed labels: Submitted By and Approved By.
        return [
            ['role' => 'submitted_by', 'action' => 'submitted', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
