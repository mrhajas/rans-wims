<?php

namespace App\Support\SupplierRecords;

use Illuminate\Database\Eloquent\Model;

/**
 * Who did each workflow step of a Purchase Request, a Payment Request or a Quotation, and when (the stamped columns added by migration
 * 2026_11_03_100000_record_supplier_request_actors), plus the matching row in the activity log.
 *
 * The stamps always describe the CURRENT standing state of the record: the attributes returned here are merged into the very update() that
 * changes the status, so a step that is taken back (rejected after approved, a quotation moved back to "Received") clears the stamps of the
 * steps that no longer stand. The activity log keeps the full history of the earlier cycles.
 * Nothing is ever back-filled: records that moved before the stamps existed simply have none.
 */
final class RequestStamps
{
    /** A request is submitted: who and when. Clears any decision stamps of an earlier cycle. */
    public static function submitted(int $userId): array
    {
        return ['submitted_by_user_id' => $userId, 'submitted_at' => now(), 'approved_by_user_id' => null, 'approved_at' => null, 'rejected_by_user_id' => null, 'rejected_at' => null];
    }

    public static function approved(int $userId): array
    {
        return ['approved_by_user_id' => $userId, 'approved_at' => now(), 'rejected_by_user_id' => null, 'rejected_at' => null];
    }

    public static function rejected(int $userId): array
    {
        return ['rejected_by_user_id' => $userId, 'rejected_at' => now(), 'approved_by_user_id' => null, 'approved_at' => null];
    }

    /**
     * A quotation moves to $status. Draft, Sent, Received and Selected are a line (a step taken back clears the later steps); Rejected is a side
     * branch that keeps what was done before it (a quotation rejected after it was received was still received) but ends any selection.
     */
    public static function quotation(string $status, int $userId): array
    {
        $steps = ['Sent' => 'sent', 'Received' => 'received', 'Selected' => 'selected', 'Rejected' => 'rejected'];
        $clear = [
            'Sent' => ['received', 'selected', 'rejected'],
            'Received' => ['selected', 'rejected'],
            'Selected' => ['rejected'],
            'Rejected' => ['selected'],
        ][$status] ?? [];
        $out = [];
        foreach ($clear as $step) {
            $out[$step . '_by_user_id'] = null;
            $out[$step . '_at'] = null;
        }
        if (isset($steps[$status])) {
            $out[$steps[$status] . '_by_user_id'] = $userId;
            $out[$steps[$status] . '_at'] = now();
        }

        return $out;
    }

    /** One explicit activity-log row for a status change, with the causer (the signed-in user) and the time. */
    public static function log(Model $record, string $what, string $event, ?string $from, ?string $to, ?string $remarks = null): void
    {
        log_activity($what . ' ' . $event . '.', array_filter([
            'event' => $event, 'reference' => $record->reference, 'from' => $from, 'to' => $to, 'remarks' => $remarks !== null && $remarks !== '' ? $remarks : null,
        ], fn ($v) => $v !== null), $record, $what);
    }
}
