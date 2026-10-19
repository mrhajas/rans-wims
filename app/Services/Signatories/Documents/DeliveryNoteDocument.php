<?php

namespace App\Services\Signatories\Documents;

use App\Models\DeliveryNote;
use App\Services\Signatories\Documents\Support\PaperworkHistory;

/**
 * Delivery note (generated from a transfer or a check-in). It is issued the moment it is generated, so its state is always "final".
 *
 * Real records: created_by_user_id + created_at (who generated it and when: GOOD) for "Prepared By". "Approved By" has no stored source on the
 * note: it uses the approval of the PARENT TRANSFER (approved_by + approved_at) only while that transfer is still approved; for a check-in
 * parent or any other transfer it is absent and the line stays blank. The physical "Received at Site" block is not part of this module.
 * No finalisation guard: the note has no approval step of its own, creating it IS the issue.
 */
final class DeliveryNoteDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'delivery_note';
    }

    public static function label(): string
    {
        return 'Delivery note';
    }

    public static function actions(): array
    {
        return [
            'prepared' => 'Prepared (the person who generated the delivery note)',
            'approved' => 'Approved (the approval of the parent transfer, when it had one)',
        ];
    }

    public function find(int $id): ?object
    {
        return DeliveryNote::query()->find($id);
    }

    public function state(object $record): string
    {
        return 'final';
    }

    public function history(object $record): array
    {
        return PaperworkHistory::history($record);
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
