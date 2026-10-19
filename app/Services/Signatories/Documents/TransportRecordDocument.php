<?php

namespace App\Services\Signatories\Documents;

use App\Models\TransportRecord;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * Transport Record (one vehicle trip), printed from the Transport Records screen.
 *
 * Real storage (TransportRecordController):
 *  - recorded   created_by_user_id + created_at                                   (GOOD)
 *  - verified   verified_by_user_id + verified_at, written once by verify() / bulkVerify() and never cleared  (GOOD)
 *  - paid       paid_by_user_id + payment_date, written by markPaid() (cash, not through a voucher) or when the linked Payment Voucher is paid.
 *               payment_date is a plain DATE, so the TIME comes from the "Transport Record payment ..." row that the same two code paths write
 *               to the activity log, and is used only when that row was caused by the same person on the same date (GOOD). Without such a row
 *               the action is reported at the start of the payment date and flagged PARTIAL (date only). Every payment overwrites these
 *               columns, so on a part-paid trip this is the person who recorded the LATEST payment (flagged PARTIAL with a note).
 *
 * Standing rules: an action is reported only when it really happened: nothing is reported as verified without verified_at, nothing as paid
 * while paid_amount is 0 (a trip merely linked to a voucher, "Pending Payment", has no payer yet).
 *
 * State: a trip is in progress from the day it is recorded (there is no draft). It is FINAL once it is verified AND fully Paid, because
 * payment is the last thing that ever happens to it; a Cancelled trip is in_progress (nothing more will be signed, but it is not a
 * completed document). The guard (required signatures that block) is applied at verify() / bulkVerify() and when markPaid() would
 * complete the payment.
 */
final class TransportRecordDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'transport_record';
    }

    public static function label(): string
    {
        return 'Transport record';
    }

    public static function actions(): array
    {
        return ['recorded' => 'Recorded (trip entered)', 'verified' => 'Verified (eligible for payment)', 'paid' => 'Paid (payment recorded)'];
    }

    public function find(int $id): ?object
    {
        return TransportRecord::query()->find($id);
    }

    public function state(object $record): string
    {
        if ($record->verified_at && $record->payment_status === TransportRecord::STATUS_PAID) {
            return 'final';
        }

        return 'in_progress';
    }

    public function history(object $record): array
    {
        $h = [];
        $at = fn ($v) => $v instanceof Carbon ? $v : ($v ? Carbon::parse($v) : null);

        $created = $at($record->created_at);
        $h['recorded'] = $this->entry($record->created_by_user_id, $created, 'The person who recorded this trip is not recorded.');

        if ($record->verified_at) {
            $h['verified'] = $this->entry($record->verified_by_user_id, $at($record->verified_at), 'The person who verified this trip is not recorded.');
        }

        if ((float) $record->paid_amount > 0 && ($record->paid_by_user_id || $record->payment_date)) {
            $h['paid'] = $this->paid($record, $at);
        }

        return $h;
    }

    private function paid(object $record, callable $at): array
    {
        $by = $record->paid_by_user_id ? (int) $record->paid_by_user_id : null;
        $date = $record->payment_date ? $at($record->payment_date)->startOfDay() : null;
        $time = null;
        if (! empty($record->paid_at_hint)) {            // the guard simulates a payment that is about to be written (not a column)
            $time = $at($record->paid_at_hint);
        } elseif ($by && $date && $record->id) {
            $row = Activity::query()->where('log_name', 'Transport Record')->where('subject_type', TransportRecord::class)->where('subject_id', $record->id)
                ->where('causer_id', $by)->where('description', 'like', 'Transport Record payment%')->orderByDesc('id')->first();
            if ($row && $row->created_at && $row->created_at->toDateString() === $date->toDateString()) {
                $time = $row->created_at;
            }
        }
        $part = $record->payment_status === TransportRecord::STATUS_PARTIALLY_PAID;
        $notes = [];
        if (! $by) {
            $notes[] = 'The person who recorded the payment is not recorded.';
        }
        if (! $time) {
            $notes[] = $date ? 'Only the payment date is recorded, not the time.' : 'The payment date is not recorded.';
        }
        if ($part) {
            $notes[] = 'Part payment: this is the person who recorded the latest payment.';
        }

        return [
            'user_id' => $by, 'at' => $time ?: $date, 'name' => null,
            'quality' => $by && $time && ! $part ? 'good' : 'partial', 'note' => $notes ? implode(' ', $notes) : null,
        ];
    }

    private function entry($userId, ?Carbon $at, string $note): array
    {
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    public static function defaultMappings(): array
    {
        // The Control card lists Recorded By, Verified By and Paid By. All three roles exist in WIMS wording.
        return [
            ['role' => 'recorded_by', 'action' => 'recorded', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'verified_by', 'action' => 'verified', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'paid_by', 'action' => 'paid', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->trip_number ?? ('#' . $record->id));
    }
}
