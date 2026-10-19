<?php

namespace App\Services\Signatories\Documents;

use App\Models\WorkforceAgreement;

/**
 * Workforce Agreement (the standing "how much was this worker agreed for on this project" record).
 *
 * The agreement has no approval workflow: creating it IS the authorisation (WorkforceAgreementPolicy), and the only status change is Active -> Cancelled.
 * Real storage (WorkforceAgreementController):
 *  - created    created_by_user_id + created_at                               (GOOD)
 *  - cancelled  cancelled_by_user_id + cancelled_at, written by cancel() from the 2026-11-03 change on (never backfilled): an agreement cancelled
 *               before it has the status but no stamp, so the action is absent (not guessed). Declared so an administrator can map it; the default
 *               setup does not use it.
 * The worker or contractor signs the paper by hand: that line is never part of the Signatories block.
 *
 * State: an agreement is in force from the moment it is saved and no signing action follows it (Cancelled is a closure, not a signing), so Active and
 * Cancelled are both 'final' (the preparer's signature is frozen as it stood when the agreement was created).
 */
final class WorkforceAgreementDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'workforce_agreement';
    }

    public static function label(): string
    {
        return 'Workforce Agreement';
    }

    public static function actions(): array
    {
        return ['created' => 'Prepared (agreement created)', 'cancelled' => 'Cancelled'];
    }

    public function find(int $id): ?object
    {
        return WorkforceAgreement::query()->find($id);
    }

    public function state(object $record): string
    {
        return 'final';
    }

    public function history(object $record): array
    {
        $out = ['created' => $this->entry($record->created_by_user_id, $record->created_at, 'The person who created the agreement is not recorded.')];

        if ($record->status === WorkforceAgreement::STATUS_CANCELLED && ($record->cancelled_by_user_id || $record->cancelled_at)) {
            $out['cancelled'] = $this->entry($record->cancelled_by_user_id, $record->cancelled_at, 'The cancellation is not fully recorded on this agreement.');
        }

        return $out;
    }

    private function entry($userId, $at, string $note): array
    {
        $at = StockSigningGuard::at($at);
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    /** A copy of an agreement as it will be once $userId creates it (nothing is saved): for the finalisation guard. */
    public function asIfCreated(int $userId): object
    {
        return (new WorkforceAgreement())->forceFill(['status' => WorkforceAgreement::STATUS_ACTIVE, 'created_by_user_id' => $userId, 'created_at' => now()]);
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'prepared_by', 'action' => 'created', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
