<?php

namespace App\Services\Signatories\Documents;

use App\Models\Checkin;

/**
 * Check-in receipt (goods received into a warehouse).
 *
 * Real records: user_id (the WIMS user who entered the check-in, i.e. who received the stock) and created_at. There is NO approval stage:
 * checkins.approved_by / approved_at exist on some databases but no code ever writes them, so they are not used. The printed "Date" is typed
 * by the user, and created_at is when the record was FIRST saved: a draft that was completed later keeps its original created_at and WIMS does
 * not time-stamp the completion, so the time is PARTIAL. The user cannot be changed by an edit (user_id is not in the request rules).
 * "Delivered By" is a supplier contact, not a WIMS user, so it stays a typed line outside the signature block.
 *
 * States: "draft" while the draft flag is set (the DRAFT watermark follows the same flag), otherwise "final" (there is nothing after a completed
 * check-in). A draft reports no "received" action: the stock has not been received yet.
 */
final class CheckInDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'check_in';
    }

    public static function label(): string
    {
        return 'Check-in receipt';
    }

    public static function actions(): array
    {
        return [
            'received' => 'Received (the person who entered the check-in)',
        ];
    }

    public function find(int $id): ?object
    {
        return Checkin::query()->withoutGlobalScope('mine')->find($id);
    }

    public function state(object $record): string
    {
        return $record->draft ? 'draft' : 'final';
    }

    public function history(object $record): array
    {
        if ($record->draft || (! $record->user_id && ! $record->created_at)) {
            return [];
        }

        return ['received' => [
            'user_id' => $record->user_id ? (int) $record->user_id : null, 'at' => StockSigningGuard::at($record->created_at), 'name' => null,
            'quality' => 'partial',
            'note' => 'Time is when the check-in was first entered in WIMS (a draft completed later is not time-stamped again), and the printed check-in date is typed by the user.',
        ]];
    }

    public static function defaultMappings(): array
    {
        // Today's printed "Received By". "Delivered By" is the supplier contact and stays a typed line.
        return [
            ['role' => 'received_by', 'action' => 'received', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
