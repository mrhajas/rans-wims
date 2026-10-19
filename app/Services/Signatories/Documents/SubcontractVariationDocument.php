<?php

namespace App\Services\Signatories\Documents;

use App\Models\SubcontractVariation as V;
use App\Services\Signatories\Documents\Support\StampedHistory;

/** Subcontract Variation. Approval based: final once Approved; a Rejected variation never reports an approval. */
final class SubcontractVariationDocument implements SignableDocument
{
    use StampedHistory;

    public static function key(): string
    {
        return 'subcontract_variation';
    }

    public static function label(): string
    {
        return 'Subcontract variation';
    }

    public static function actions(): array
    {
        return ['requested' => 'Requested (variation entered)', 'submitted' => 'Submitted', 'reviewed' => 'Reviewed', 'approved' => 'Approved'];
    }

    public function find(int $id): ?object
    {
        return V::withoutGlobalScopes()->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->status) { V::DRAFT => 'draft', V::APPROVED => 'final', default => 'in_progress' };
    }

    public function history(object $record): array
    {
        $h = [];
        $this->put($h, 'requested', $record->requested_by_user_id, $record->created_at, 'Who entered this variation, or when, was not recorded.');
        $this->put($h, 'submitted', $record->submitted_by_user_id, $record->submitted_at, 'The submission is not fully recorded on this variation.');
        if (in_array($record->status, [V::REVIEWED, V::APPROVED], true)) {
            $this->put($h, 'reviewed', $record->reviewed_by_user_id, $record->reviewed_at, 'The review is not fully recorded on this variation.');
        }
        if ($record->status === V::APPROVED) {
            $this->put($h, 'approved', $record->approved_by_user_id, $record->approved_at, 'The approval is not fully recorded on this variation.');
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'requested_by', 'action' => 'requested', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'reviewed_by', 'action' => 'reviewed', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}
