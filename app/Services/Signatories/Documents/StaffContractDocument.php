<?php

namespace App\Services\Signatories\Documents;

use App\Models\StaffContract;

/**
 * Staff contract record (Staff > Contracts, "Employment Contract Record" print / PDF).
 *
 * Real storage (StaffContractController):
 *  - prepared  created_by_user_id + created_at, written by store() and by renew() (the renewal is a new contract made by the person renewing).
 *              created_by_user_id is nullable: contracts created before the column existed (and sample data) have no author, and are then
 *              reported as 'partial' (not recorded) instead of guessed.
 *
 * Deliberately NOT mapped:
 *  - signed_date: a DATE typed by hand on the form for the day the employee and employer signed on paper. It is not a WIMS user action and
 *    has no user or time, so it is never read as a signature event.
 *  - stopped_by_user_id + stopped_on: a real action, but the printed sheet has no label for it, so it is not offered. (stopped_on is a date.)
 *  - HR approval / authorised signatory: nothing in staff_contracts or staff_history stores either. staff_history only records who added,
 *    edited, renewed, stopped or deleted a contract (event + user_id + created_at), never an approval.
 * The Employee, Human Resources and Authorised Signatory columns on the sheet are physical signatures and always stay as printed; a registered
 * WIMS signature is never placed on them.
 *
 * State: there is no draft and no approval stage. The one signing action (prepared) is complete the moment the contract is saved and nothing
 * can add another, so the record is always 'final'. That also makes the signature the one valid when the contract was drawn up (frozen on
 * the first print), not whatever the person has registered since. There is therefore no finalisation guard: no workflow step exists to block.
 */
final class StaffContractDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'staff_contract';
    }

    public static function label(): string
    {
        return 'Staff Contract';
    }

    public static function actions(): array
    {
        return ['prepared' => 'Prepared (contract drawn up in WIMS)'];
    }

    public function find(int $id): ?object
    {
        return StaffContract::query()->find($id);
    }

    public function state(object $record): string
    {
        return 'final';
    }

    public function history(object $record): array
    {
        $at = StockSigningGuard::at($record->created_at);
        $ok = $record->created_by_user_id && $at;

        return ['prepared' => [
            'user_id' => $record->created_by_user_id ? (int) $record->created_by_user_id : null, 'at' => $at, 'name' => null,
            'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : 'The person who drew up this contract is not recorded.',
        ]];
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'prepared_by', 'action' => 'prepared', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->contract_number ?? ('#' . $record->id));
    }
}
