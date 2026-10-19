<?php

namespace App\Services\Signatories\Documents;

use App\Models\Accounts\AcctTransfer as T;
use App\Services\Signatories\Documents\Support\StampedHistory;

/**
 * Accounts Tracking transfer. Approval based: final once Approved, Posted or Reversed (a posted entry is in the ledger).
 * Real records: prepared (created_by + created_at), submitted (submitted_by + submitted_at), approved/posted (posted_by + posted_at; the approver
 * is the person who posted it). A Rejected or Cancelled transfer never reports an approval.
 */
final class AccountsTransferDocument implements SignableDocument
{
    use StampedHistory;

    public static function key(): string
    {
        return 'acct_transfer';
    }

    public static function label(): string
    {
        return 'Accounts transfer';
    }

    public static function actions(): array
    {
        return ['prepared' => 'Prepared (transfer entered)', 'submitted' => 'Submitted for approval', 'approved' => 'Approved and posted'];
    }

    public function find(int $id): ?object
    {
        return T::query()->find($id);
    }

    public function state(object $record): string
    {
        return $record->status === 'draft' ? 'draft' : (in_array($record->status, ['approved', 'posted', 'reversed'], true) ? 'final' : 'in_progress');
    }

    public function history(object $record): array
    {
        $h = [];
        $this->put($h, 'prepared', $record->created_by, $record->created_at, 'Who entered this transfer, or when, was not recorded.');
        $this->put($h, 'submitted', $record->submitted_by, $record->submitted_at, 'The submission is not fully recorded on this transfer.');
        if (in_array($record->status, ['approved', 'posted', 'reversed'], true)) {
            $this->put($h, 'approved', $record->posted_by, $record->posted_at, 'The approval is not fully recorded on this transfer.');
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'prepared_by', 'action' => 'prepared', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
