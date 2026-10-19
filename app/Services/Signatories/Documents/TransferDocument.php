<?php

namespace App\Services\Signatories\Documents;

use App\Models\Transfer;

/**
 * Transfer note (stock moved between warehouses).
 *
 * Real records: user_id + created_at (who raised it and when it was entered), approved_by + approved_at (only transfers leaving a MAIN warehouse
 * need approval; the pair is cleared by an edit that resets approval), received_by + received_at (the destination confirmation).
 *
 * States: "draft" = a plain draft (draft flag set and not waiting for approval and not cancelled). "final" = received (received_at set, not a draft,
 * not cancelled): that is the last step of a transfer. Everything else, including a transfer waiting for approval and a cancelled one, is
 * "in_progress" (a cancelled transfer never gets another signing action, but it was not issued either).
 */
final class TransferDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'transfer';
    }

    public static function label(): string
    {
        return 'Transfer note';
    }

    public static function actions(): array
    {
        return [
            'dispatched' => 'Dispatched (the person who entered the transfer)',
            'approved' => 'Approved (transfers leaving a main warehouse)',
            'received' => 'Received at the destination',
        ];
    }

    public function find(int $id): ?object
    {
        return Transfer::query()->withoutGlobalScope('mine')->find($id);
    }

    public function state(object $record): string
    {
        if ($this->cancelled($record)) {
            return 'in_progress';
        }
        if ($record->received_at && ! $record->draft) {
            return 'final';
        }
        if ($record->draft && $record->approval_status !== Transfer::APPROVAL_PENDING) {
            return 'draft';
        }

        return 'in_progress';
    }

    public function history(object $record): array
    {
        $h = [];
        $cancelled = $this->cancelled($record);

        // dispatched: the creator and the real creation time (the printed "Date" is user-entered and can differ).
        if ($record->user_id || $record->created_at) {
            $h['dispatched'] = [
                'user_id' => $record->user_id ? (int) $record->user_id : null, 'at' => StockSigningGuard::at($record->created_at), 'name' => null,
                'quality' => 'partial',
                'note' => 'Time is when the transfer was entered in WIMS. A draft that was finalised later is not time-stamped separately, and the printed transfer date is typed by the user.',
            ];
        }

        // approved: only while the transfer is currently approved (an edit resets approval to pending and clears the stamp; a cancel never follows an approval).
        if (! $cancelled && $record->approval_status === Transfer::APPROVAL_APPROVED) {
            $h['approved'] = [
                'user_id' => $record->approved_by ? (int) $record->approved_by : null, 'at' => StockSigningGuard::at($record->approved_at), 'name' => null,
                'quality' => $record->approved_by && $record->approved_at ? 'good' : 'partial',
                'note' => $record->approved_by && $record->approved_at ? null : 'The transfer is marked approved but who approved it, or when, was not stamped.',
            ];
        }

        // received: a received transfer that was pulled back to draft by an edit is not a standing receipt.
        if (! $cancelled && ! $record->draft && $record->received_at) {
            $h['received'] = [
                'user_id' => $record->received_by ? (int) $record->received_by : null, 'at' => StockSigningGuard::at($record->received_at), 'name' => null,
                'quality' => $record->received_by ? 'good' : 'partial',
                'note' => $record->received_by ? null : 'The receipt has a time but the person who confirmed it was not stamped.',
            ];
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        // Printed labels: Dispatched By, Received By. "Approved By" exists in the data (transfers leaving a main warehouse) but was never printed, so it appears only when that approval really happened.
        return [
            ['role' => 'dispatched_by', 'action' => 'dispatched', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'received_by', 'action' => 'received', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 3, 'required' => false, 'show_when' => 'after'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }

    private function cancelled(object $record): bool
    {
        return $record->approval_status === Transfer::APPROVAL_CANCELLED;
    }
}
