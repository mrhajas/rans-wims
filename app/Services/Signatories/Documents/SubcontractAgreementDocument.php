<?php

namespace App\Services\Signatories\Documents;

use App\Models\SubcontractAgreement as A;
use App\Services\Signatories\Documents\Support\StampedHistory;

/**
 * Subcontract Agreement. Approval based: the document is final once approved (Approved, Active, Suspended, Completed, Terminated, Archived).
 *
 * Real records: prepared (user_id + created_at), submitted, reviewed, approved (each a stamped user + time). A reviewed or approved stamp is reported only
 * while the agreement stands past that step; a draft, or an agreement sent back to Draft, shows no approval. Nothing is back-filled: an older agreement
 * without a stamp simply has no such action.
 */
final class SubcontractAgreementDocument implements SignableDocument
{
    use StampedHistory;

    private const FINAL = [A::APPROVED, A::ACTIVE, A::SUSPENDED, A::COMPLETED, A::TERMINATED, A::ARCHIVED];

    public static function key(): string
    {
        return 'subcontract_agreement';
    }

    public static function label(): string
    {
        return 'Subcontract agreement';
    }

    public static function actions(): array
    {
        return ['prepared' => 'Prepared (agreement entered)', 'submitted' => 'Submitted for review', 'reviewed' => 'Reviewed', 'approved' => 'Approved'];
    }

    public function find(int $id): ?object
    {
        return A::withoutGlobalScopes()->find($id);
    }

    public function state(object $record): string
    {
        return $record->status === A::DRAFT ? 'draft' : (in_array($record->status, self::FINAL, true) ? 'final' : 'in_progress');
    }

    public function history(object $record): array
    {
        $h = [];
        $this->put($h, 'prepared', $record->user_id, $record->created_at, 'Who entered this agreement, or when, was not recorded.');
        $this->put($h, 'submitted', $record->submitted_by_user_id, $record->submitted_at, 'The submission is not fully recorded on this agreement.');
        if (in_array($record->status, [A::REVIEW, ...self::FINAL], true)) {
            $this->put($h, 'reviewed', $record->reviewed_by_user_id, $record->reviewed_at, 'The review is not fully recorded on this agreement.');
        }
        if (in_array($record->status, self::FINAL, true)) {
            $this->put($h, 'approved', $record->approved_by_user_id, $record->approved_at, 'The approval is not fully recorded on this agreement.');
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'prepared_by', 'action' => 'prepared', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'reviewed_by', 'action' => 'reviewed', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
