<?php

namespace App\Services\Signatories\Documents;

use App\Models\IssueTransaction;

/**
 * Issue slip (Issue and Return module). The slip is the printout of one issue transaction.
 *
 * Real records: user_id + created_at of the issue transaction (who issued the materials and when it was entered: GOOD; the issue date is a typed
 * date/time, the signature uses the stamped creation time). user_id cannot be changed by an edit. "Issued To" and the returns' "Returned By" /
 * "Received By" are typed names (not WIMS users) and stay exactly as printed; the Returns Timeline is untouched.
 *
 * State: always "final". There is no draft or approval, so once an issue exists nothing more is signed on it (a later return is a separate record).
 * No finalisation guard for the same reason.
 */
final class IssueSlipDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'issue_slip';
    }

    public static function label(): string
    {
        return 'Issue slip';
    }

    public static function actions(): array
    {
        return [
            'issued' => 'Issued (the person who entered the issue)',
        ];
    }

    public function find(int $id): ?object
    {
        return IssueTransaction::query()->withoutGlobalScope('mine')->find($id);
    }

    public function state(object $record): string
    {
        return 'final';
    }

    public function history(object $record): array
    {
        if (! $record->user_id && ! $record->created_at) {
            return [];
        }
        $good = $record->user_id && $record->created_at;

        return ['issued' => [
            'user_id' => $record->user_id ? (int) $record->user_id : null, 'at' => StockSigningGuard::at($record->created_at), 'name' => null,
            'quality' => $good ? 'good' : 'partial',
            'note' => $good ? null : 'Who issued this, or when, was not recorded.',
        ]];
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'issued_by', 'action' => 'issued', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->issue_number ?? ('#' . $record->id));
    }
}
