<?php

namespace App\Services\Signatories\Documents;

use App\Models\ExternalStockMovement as M;
use App\Services\Signatories\Documents\Support\StampedHistory;

/**
 * External Stock movement (Stock In, Stock Out, Opening stock). Approval based: it is final once Approved and only then does the balance of the person move.
 * Real records: recorded (user_id + created_at), decided (decided_by_user_id + decided_at, reported as "approved" only while the status is Approved,
 * as "rejected" only while it is Rejected), cancelled (cancelled_by_user_id + cancelled_at).
 */
final class ExternalStockDocument implements SignableDocument
{
    use StampedHistory;

    public static function key(): string
    {
        return 'external_stock';
    }

    public static function label(): string
    {
        return 'External stock movement';
    }

    public static function actions(): array
    {
        return ['recorded' => 'Recorded (movement entered)', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
    }

    public function find(int $id): ?object
    {
        return M::withoutGlobalScopes()->find($id);
    }

    public function state(object $record): string
    {
        return $record->status === M::APPROVED ? 'final' : 'in_progress';
    }

    public function history(object $record): array
    {
        $h = [];
        $this->put($h, 'recorded', $record->user_id, $record->created_at, 'Who recorded this movement, or when, was not recorded.');
        if ($record->status === M::APPROVED) {
            $this->put($h, 'approved', $record->decided_by_user_id, $record->decided_at, 'The approval is not fully recorded on this movement.');
        } elseif ($record->status === M::REJECTED) {
            $this->put($h, 'rejected', $record->decided_by_user_id, $record->decided_at, 'The rejection is not fully recorded on this movement.');
        } elseif ($record->status === M::CANCELLED) {
            $this->put($h, 'cancelled', $record->cancelled_by_user_id, $record->cancelled_at, 'The cancellation is not fully recorded on this movement.');
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'recorded_by', 'action' => 'recorded', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
