<?php

namespace App\Services\Signatories\Documents;

use App\Models\Adjustment;

/**
 * Stock adjustment (Pending / Approved / Rejected).
 *
 * Real records: user_id + created_at (who requested it, and the real entry time; the printed Date is typed by the user), approved_by + approved_at
 * (+ approved_by_role), rejected_by + rejected_at. AdjustmentStatusService::change() clears both pairs on every status move, so only the standing
 * state is on the row; this adapter additionally ignores an approval stamp on a Rejected record.
 *
 * States: "final" = Approved. Pending and Rejected are "in_progress" (a Rejected adjustment can still be re-approved by a Super Admin). A Rejected
 * record never reports an approval; the rejecter is the "rejected" action.
 */
final class AdjustmentDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'adjustment';
    }

    public static function label(): string
    {
        return 'Stock adjustment';
    }

    public static function actions(): array
    {
        return [
            'requested' => 'Requested (the person who entered the adjustment)',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];
    }

    public function find(int $id): ?object
    {
        return Adjustment::query()->withoutGlobalScope('mine')->find($id);
    }

    public function state(object $record): string
    {
        return $record->status === Adjustment::STATUS_APPROVED ? 'final' : 'in_progress';
    }

    public function history(object $record): array
    {
        $h = [];

        if ($record->user_id || $record->created_at) {
            $h['requested'] = [
                'user_id' => $record->user_id ? (int) $record->user_id : null, 'at' => StockSigningGuard::at($record->created_at), 'name' => null,
                'quality' => $record->user_id && $record->created_at ? 'good' : 'partial',
                'note' => 'Time is when the adjustment was entered in WIMS; the printed adjustment date is typed by the user.',
            ];
        }

        if ($record->status === Adjustment::STATUS_APPROVED) {
            $h['approved'] = [
                'user_id' => $record->approved_by ? (int) $record->approved_by : null, 'at' => StockSigningGuard::at($record->approved_at), 'name' => null,
                'quality' => $record->approved_by && $record->approved_at ? 'good' : 'partial',
                'note' => $record->approved_by && $record->approved_at ? null : 'The adjustment is approved but who approved it, or when, was not stamped.',
            ];
        } elseif ($record->status === Adjustment::STATUS_REJECTED) {
            $h['rejected'] = [
                'user_id' => $record->rejected_by ? (int) $record->rejected_by : null, 'at' => StockSigningGuard::at($record->rejected_at), 'name' => null,
                'quality' => $record->rejected_by ? 'good' : 'partial',
                'note' => $record->rejected_by ? null : 'The rejection has a time but the person who rejected it was not stamped.',
            ];
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        // Printed labels: Requested By and Approved By. "Rejected" is declared but not mapped by default (no Rejected By role): the old print swapped the second label to Rejected By.
        return [
            ['role' => 'requested_by', 'action' => 'requested', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
