<?php

namespace App\Services\Signatories\Documents;

use App\Models\ProjectCertificateContractAddendum;
use Illuminate\Support\Carbon;

/**
 * Contract Addendum. Real records: created_by + created_at (the preparer) and approved_by + approved_at (stamped when the status is set to
 * Approved).
 *
 * Statuses: draft -> pending_approval -> approved | rejected (the "returned" state; it can go back to draft or pending_approval). Approved is
 * a hard lock (updateStatus has no way out of it), and approved_by/approved_at are written only together with the Approved status, so an
 * approval is reported only while the status is Approved; a draft, pending or rejected addendum never carries one. A rejection has no actor
 * column, so it is not an action here. The "Signed By (Client)" line is typed text about a person outside WIMS and stays outside this module.
 */
final class ContractAddendumDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'contract_addendum';
    }

    public static function label(): string
    {
        return 'Contract Addendum';
    }

    public static function actions(): array
    {
        return [
            'created' => 'Prepared (addendum created by the Quantity Surveyor)',
            'approved' => 'Approved',
        ];
    }

    public function find(int $id): ?object
    {
        return ProjectCertificateContractAddendum::withoutGlobalScopes()->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->status) {
            ProjectCertificateContractAddendum::STATUS_DRAFT => 'draft',
            ProjectCertificateContractAddendum::STATUS_APPROVED => 'final',
            default => 'in_progress', // pending approval, rejected (returned)
        };
    }

    public function history(object $record): array
    {
        $h = [];
        if ($record->created_by || $record->created_at) {
            $h['created'] = $this->entry($record->created_by, $record->created_at, $record->created_by ? null : 'The person who created this addendum is not recorded.');
        }
        if ($record->status === ProjectCertificateContractAddendum::STATUS_APPROVED) {
            $note = null;
            if (! $record->approved_by) {
                $note = 'The addendum is approved but the person who approved it is not recorded.';
            } elseif (! $record->approved_at) {
                $note = 'The date and time of the approval are not recorded.';
            }
            $h['approved'] = $this->entry($record->approved_by, $record->approved_at, $note);
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'prepared_by', 'action' => 'created', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return 'Addendum No. ' . $record->addendum_number;
    }

    /** A copy of the addendum as it will be once $userId approves it (nothing is saved): for the finalisation guard. */
    public function asIfApproved(object $record, int $userId): object
    {
        $copy = clone $record;
        $copy->forceFill(['status' => ProjectCertificateContractAddendum::STATUS_APPROVED, 'approved_by' => $userId, 'approved_at' => now()]);

        return $copy;
    }

    private function entry(?int $userId, $at, ?string $note): array
    {
        return [
            'user_id' => $userId ? (int) $userId : null,
            'at' => $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : null),
            'name' => null,
            'quality' => $note ? 'partial' : 'good',
            'note' => $note,
        ];
    }
}
