<?php

namespace App\Console\Commands;

use App\Models\ComplianceHistory;
use App\Models\ComplianceRecord;
use App\Models\ComplianceReminderLog;
use App\Models\ComplianceRenewalHistory;
use App\Services\ComplianceNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessComplianceExpiries extends Command
{
    protected $signature = 'compliance:process-expiries';

    protected $description = 'Recalculate compliance status/priority, send configured reminders, and escalate records approaching or past expiry.';

    // Fixed escalation tiers (simple, hard-coded for v1 — see the
    // Compliance Management plan's "configurable escalation-rules builder"
    // deferral). Notifies the record's escalation_recipient_user_id in
    // addition to the normal reminder recipients.
    private const ESCALATION_DAYS = [14, 7, 0];
    private const JUST_EXPIRED_DAY = -1;

    public function handle(): int
    {
        $records = ComplianceRecord::query()->whereNotIn('status', ['Cancelled', 'Rejected', 'Completed'])->get();

        $recalculated = 0;
        $remindersSent = 0;

        foreach ($records as $record) {
            try {
                // a record with auto renewal tells its owners before the renewal and renews itself on the expiry date
                if ($record->autoRenewalActive()) {
                    $remindersSent += $this->processAutoRenewal($record);
                    $record->refresh();
                }

                if ($record->recalculateStatusAndPriority()) {
                    $recalculated++;
                }

                $days = $record->days_remaining;
                if ($days === null) {
                    continue;
                }

                // Contracts remind based on notice period, not raw expiry —
                // "90 days before expiry" for a 90-day notice period means
                // the reminder fires 90 days out from days_remaining==0,
                // i.e. when the notice-adjusted countdown hits a configured
                // interval.
                $effectiveDays = $record->contract_notice_period_days
                    ? $days - $record->contract_notice_period_days
                    : $days;

                $intervals = $record->reminder_intervals ?: ComplianceRecord::DEFAULT_REMINDER_INTERVALS;

                if (in_array($effectiveDays, $intervals, true)) {
                    $remindersSent += $this->sendReminders($record, $effectiveDays);
                }

                if (in_array($days, self::ESCALATION_DAYS, true) || $days === self::JUST_EXPIRED_DAY) {
                    $remindersSent += $this->sendEscalation($record, $days);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to process compliance expiry', [
                    'compliance_record_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Recalculated {$recalculated} record(s), sent {$remindersSent} reminder(s)/escalation(s).");

        return 0;
    }

    /** Auto renewal: the notices on the chosen days before, then the renewal itself on (or after) the expiry date. Returns how many people were told. */
    private function processAutoRenewal(ComplianceRecord $record): int
    {
        $told = 0;
        $days = $record->days_remaining;
        if ($days === null) {
            return 0;
        }
        $newExpiryText = fn (int $n) => $record->expiry_date->copy()->addDays($n)->format('d M Y');

        foreach ($record->autoRenewNoticeDays() as $n) {
            if ($days !== $n || $this->noticeAlreadySent($record, $n)) {
                continue;
            }
            $message = $n === 0
                ? __(':title (:number) renews itself today for :days day(s), new expiry :new.', ['title' => $record->title, 'number' => $record->compliance_number, 'days' => (int) $record->auto_renew_days, 'new' => $newExpiryText((int) $record->auto_renew_days)])
                : __(':title (:number) renews itself in :n day(s), on :date, for :days day(s), new expiry :new.', ['title' => $record->title, 'number' => $record->compliance_number, 'n' => $n, 'date' => $record->expiry_date->format('d M Y'), 'days' => (int) $record->auto_renew_days, 'new' => $newExpiryText((int) $record->auto_renew_days)]);
            $people = ComplianceNotifier::remind('auto_renewal_notice', $record, __('Auto renewal notice - :title', ['title' => $record->title]), $message, 'an' . $n . '-' . $record->expiry_date->toDateString());
            foreach ($people as $user) {
                $this->logNotice($record, $n, $user->id);
            }
            if ($people->isEmpty()) {
                // nobody to tell (or the event is off): still remember it, so the day is not retried all day
                $this->logNotice($record, $n, null);
            } else {
                ComplianceHistory::log($record, 'Auto Renewal Notice', __(':days day(s) notice sent to :count recipient(s).', ['days' => $n, 'count' => $people->count()]));
            }
            $told += $people->count();
        }

        // renew on the expiry day (or at the first run after it); never while a manual renewal is under way
        if ($days <= 0 && ! $record->activeRenewal) {
            $told += $this->autoRenew($record);
        }

        return $told;
    }

    /** One automatic renewal: the expiry moves forward by the chosen period (enough periods to be in the future if the job missed days). */
    private function autoRenew(ComplianceRecord $record): int
    {
        $period = (int) $record->auto_renew_days;
        $prevIssue = $record->issue_date;
        $prevExpiry = $record->expiry_date->copy();
        $today = now()->startOfDay();
        $periods = max(1, intdiv((int) $prevExpiry->diffInDays($today, false), $period) + 1);
        $newIssue = $prevExpiry->copy();
        $newExpiry = $prevExpiry->copy()->addDays($period * $periods);

        ComplianceRenewalHistory::create([
            'compliance_record_id' => $record->id,
            'previous_issue_date' => $prevIssue,
            'previous_expiry_date' => $prevExpiry,
            'new_issue_date' => $newIssue,
            'new_expiry_date' => $newExpiry,
            'renewal_started_at' => now(),
            'renewed_at' => now(),
            'status' => 'Renewed',
            'notes' => __('Automatic renewal'),
        ]);
        $record->update([
            'issue_date' => $newIssue,
            'expiry_date' => $newExpiry,
            'status' => 'Active',
            'auto_renewed_count' => (int) $record->auto_renewed_count + 1,
            'last_auto_renewed_at' => now(),
        ]);
        $record->refresh();
        $record->recalculateStatusAndPriority();
        ComplianceHistory::log($record, 'Auto Renewed', __('Renewed itself automatically: new expiry :date (:days days).', ['date' => $newExpiry->format('d M Y'), 'days' => $period * $periods]));

        $people = ComplianceNotifier::remind('auto_renewed', $record, __('Auto renewed - :title', ['title' => $record->title]),
            __(':title (:number) renewed itself automatically. New expiry date: :date.', ['title' => $record->title, 'number' => $record->compliance_number, 'date' => $newExpiry->format('d M Y')]),
            'ar-' . $newExpiry->toDateString());

        return $people->count();
    }

    /** A notice is sent once per record, notice day and renewal cycle (a cycle ends with the renewal that follows it). */
    private function noticeAlreadySent(ComplianceRecord $record, int $days): bool
    {
        return ComplianceReminderLog::query()->where('compliance_record_id', $record->id)->where('interval_days', 20000 + $days)
            ->where('renewal_history_id', $record->renewalHistory()->value('id'))->exists();
    }

    private function logNotice(ComplianceRecord $record, int $days, ?int $recipientId): void
    {
        ComplianceReminderLog::create([
            'compliance_record_id' => $record->id,
            'renewal_history_id' => $record->renewalHistory()->value('id'),
            'interval_days' => 20000 + $days,
            'sent_at' => now(),
            'channel' => 'auto_renewal',
            'recipient_user_id' => $recipientId,
            'status' => $recipientId ? 'Sent' : 'Skipped',
        ]);
    }

    private function sendReminders(ComplianceRecord $record, int $intervalKey): int
    {
        // one notice per record, renewal cycle and reminder day (the Compliance workflow page decides who is told)
        if ($this->alreadySentForRecord($record, $intervalKey)) {
            return 0;
        }

        $days = $record->days_remaining;
        $message = $days < 0
            ? __(':title (:number) expired :days day(s) ago. Action required: renew.', ['title' => $record->title, 'number' => $record->compliance_number, 'days' => abs($days)])
            : __(':title (:number) expires in :days day(s). Action required: renew.', ['title' => $record->title, 'number' => $record->compliance_number, 'days' => $days]);

        $told = ComplianceNotifier::remind('reminder', $record, __('Compliance renewal required - :title', ['title' => $record->title]), $message, 'r' . $intervalKey . '-' . optional($record->activeRenewal)->id);

        foreach ($told as $user) {
            $this->logReminder($record, $intervalKey, $user->id, 'database');
        }

        if ($told->isNotEmpty()) {
            ComplianceHistory::log($record, 'Reminder Sent', __(':days day(s) reminder sent to :count recipient(s).', ['days' => $intervalKey, 'count' => $told->count()]));
        }

        return $told->count();
    }

    private function sendEscalation(ComplianceRecord $record, int $days): int
    {
        // Escalation dedupe shares the same log table but a distinct
        // negative-offset "interval" namespace (10000 - days) so it never
        // collides with a normal reminder interval sharing the same day.
        $escalationKey = 10000 - $days;

        if ($this->alreadySentForRecord($record, $escalationKey)) {
            return 0;
        }

        $message = $days < 0
            ? __(':title (:number) expired :days day(s) ago. Action required: renew.', ['title' => $record->title, 'number' => $record->compliance_number, 'days' => abs($days)])
            : __(':title (:number) expires in :days day(s). Action required: renew.', ['title' => $record->title, 'number' => $record->compliance_number, 'days' => $days]);

        $told = ComplianceNotifier::remind('escalation', $record, __('Escalation - :title', ['title' => $record->title]), $message, 'e' . $escalationKey . '-' . optional($record->activeRenewal)->id, $record->escalationRecipient);

        if ($told->isEmpty()) {
            return 0;
        }
        foreach ($told as $user) {
            $this->logReminder($record, $escalationKey, $user->id, 'escalation');
        }
        ComplianceHistory::log($record, 'Escalated', __('Escalated to :name.', ['name' => $told->pluck('name')->implode(', ') ?: '-']));

        return $told->count();
    }

    private function alreadySentForRecord(ComplianceRecord $record, int $intervalKey): bool
    {
        return ComplianceReminderLog::query()
            ->where('compliance_record_id', $record->id)
            ->where('renewal_history_id', optional($record->activeRenewal)->id)
            ->where('interval_days', $intervalKey)
            ->exists();
    }

    private function logReminder(ComplianceRecord $record, int $intervalKey, int $recipientId, string $channel): void
    {
        ComplianceReminderLog::create([
            'compliance_record_id' => $record->id,
            'renewal_history_id' => optional($record->activeRenewal)->id,
            'interval_days' => $intervalKey,
            'sent_at' => now(),
            'channel' => $channel,
            'recipient_user_id' => $recipientId,
            'status' => 'Sent',
        ]);
    }
}
