<?php

namespace App\Services\Signatories\Documents;

use App\Models\Checkout;

/**
 * Check-out receipt (materials issued from a warehouse).
 *
 * Real records: user_id (the WIMS user who entered the check-out, i.e. who issued the stock) and created_at. There is NO approval stage
 * (checkouts.approved_by / approved_at are never written, so they are not used). The printed "Date" is typed by the user; created_at is when the
 * record was FIRST saved (a draft completed later is not time-stamped again), so the time is PARTIAL. The user cannot be changed by an edit.
 * "Received By" on the printed check-out is a Contact (not a WIMS user) and stays a typed line outside the signature block.
 *
 * States: "draft" while the draft flag is set, otherwise "final". A draft reports no "issued" action: nothing has left the warehouse yet.
 */
final class CheckOutDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'check_out';
    }

    public static function label(): string
    {
        return 'Check-out receipt';
    }

    public static function actions(): array
    {
        return [
            'issued' => 'Issued (the person who entered the check-out)',
        ];
    }

    public function find(int $id): ?object
    {
        return Checkout::query()->withoutGlobalScope('mine')->find($id);
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

        return ['issued' => [
            'user_id' => $record->user_id ? (int) $record->user_id : null, 'at' => StockSigningGuard::at($record->created_at), 'name' => null,
            'quality' => 'partial',
            'note' => 'Time is when the check-out was first entered in WIMS (a draft completed later is not time-stamped again), and the printed check-out date is typed by the user.',
        ]];
    }

    public static function defaultMappings(): array
    {
        // Today's printed "Issued By". The receiver is a contact and stays a typed line.
        return [
            ['role' => 'issued_by', 'action' => 'issued', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
