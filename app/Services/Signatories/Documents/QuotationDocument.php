<?php

namespace App\Services\Signatories\Documents;

use App\Models\Quotation;

/**
 * Quotation (a supplier's price offer recorded in Supplier Records).
 *
 * Real records:
 *  - recorded   created_by + created_at: the person who recorded the quotation and the moment it was entered. GOOD.
 *  - sent       sent_by_user_id + sent_at   } stamped by the status change since migration 2026_11_03_100000. Older quotations have no stamps, so these actions
 *  - received   received_by_user_id + received_at } are absent for them (never back-filled). The stamps follow the status: a step taken back clears the later steps
 *  - selected   selected_by_user_id + selected_at } (see App\Support\SupplierRecords\RequestStamps), a rejection keeps what was done before it but ends a selection.
 *  - rejected   rejected_by_user_id + rejected_at }
 * An action is reported only while the current status still includes it: sent from Sent onwards, received from Received onwards, selected only while Selected,
 * rejected only while Rejected.
 *
 * States: "draft" = status Draft. "final" = Selected (the buying decision, the last signing step). Sent, Received and Rejected are "in_progress" (a rejected
 * quotation can still be put back by the status control).
 */
final class QuotationDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'quotation';
    }

    public static function label(): string
    {
        return 'Quotation';
    }

    public static function actions(): array
    {
        return [
            'recorded' => 'Recorded (the person who entered the quotation)',
            'sent' => 'Sent to the supplier',
            'received' => 'Received from the supplier',
            'selected' => 'Selected (the supplier chosen)',
            'rejected' => 'Rejected',
        ];
    }

    public function find(int $id): ?object
    {
        return Quotation::query()->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->status) {
            Quotation::STATUS_DRAFT => 'draft',
            Quotation::STATUS_SELECTED => 'final',
            default => 'in_progress',
        };
    }

    public function history(object $record): array
    {
        $h = [];
        $status = $record->status;

        if ($record->created_by || $record->created_at) {
            $h['recorded'] = $this->entry($record->created_by, $record->created_at, 'Who recorded this quotation, or when, was not recorded.');
        }
        $stands = [
            'sent' => [Quotation::STATUS_SENT, Quotation::STATUS_RECEIVED, Quotation::STATUS_SELECTED, Quotation::STATUS_REJECTED],
            'received' => [Quotation::STATUS_RECEIVED, Quotation::STATUS_SELECTED, Quotation::STATUS_REJECTED],
            'selected' => [Quotation::STATUS_SELECTED],
            'rejected' => [Quotation::STATUS_REJECTED],
        ];
        foreach ($stands as $step => $statuses) {
            $by = $record->{$step . '_by_user_id'};
            $at = $record->{$step . '_at'};
            if (in_array($status, $statuses, true) && ($by || $at)) {
                $h[$step] = $this->entry($by, $at, 'The ' . $step . ' step is not fully recorded on this quotation.');
            }
        }

        return $h;
    }

    private function entry($userId, $at, string $note): array
    {
        $at = StockSigningGuard::at($at);
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'recorded_by', 'action' => 'recorded', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'selected', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }

    /** A copy of the quotation as it will be once $userId selects it (nothing is saved): for the finalisation guard. */
    public function asIfSelected(object $record, int $userId): object
    {
        $copy = clone $record;
        $copy->forceFill(['status' => Quotation::STATUS_SELECTED, 'selected_by_user_id' => $userId, 'selected_at' => now(), 'rejected_by_user_id' => null, 'rejected_at' => null]);

        return $copy;
    }
}
