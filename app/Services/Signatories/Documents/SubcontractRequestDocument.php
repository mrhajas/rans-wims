<?php

namespace App\Services\Signatories\Documents;

use App\Models\SubcontractPaymentRequest as R;
use App\Services\Signatories\Documents\Support\StampedHistory;

/**
 * Subcontract Payment Request. Approval based: final once Approved. The money is paid later through a Payment Voucher, which has its own signatures,
 * so "paid" is not an action here. Returned and Rejected requests never report an approval.
 */
final class SubcontractRequestDocument implements SignableDocument
{
    use StampedHistory;

    public static function key(): string
    {
        return 'subcontract_request';
    }

    public static function label(): string
    {
        return 'Subcontract payment request';
    }

    public static function actions(): array
    {
        return ['requested' => 'Requested (request entered)', 'submitted' => 'Submitted', 'reviewed' => 'Reviewed', 'approved' => 'Approved'];
    }

    public function find(int $id): ?object
    {
        return R::withoutGlobalScopes()->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->status) { R::DRAFT => 'draft', R::APPROVED => 'final', default => 'in_progress' };
    }

    public function history(object $record): array
    {
        $h = [];
        $this->put($h, 'requested', $record->requested_by_user_id, $record->created_at, 'Who entered this request, or when, was not recorded.');
        if (! in_array($record->status, [R::DRAFT], true)) {
            $this->put($h, 'submitted', $record->submitted_by_user_id, $record->submitted_at, 'The submission is not fully recorded on this request.');
        }
        if (in_array($record->status, [R::REVIEW, R::APPROVED], true)) {
            $this->put($h, 'reviewed', $record->reviewed_by_user_id, $record->reviewed_at, 'The review is not fully recorded on this request.');
        }
        if ($record->status === R::APPROVED) {
            $this->put($h, 'approved', $record->approved_by_user_id, $record->approved_at, 'The approval is not fully recorded on this request.');
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
