<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\DigitalAttendanceEvent;
use App\Models\DigitalAttendanceRecord;
use App\Models\User;
use App\Services\DigitalAttendanceNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessDigitalAttendance extends Command
{
    protected $signature = 'digital-attendance:process';

    protected $description = 'Flags missing clock-outs, and sends clock-in reminder / long-session / missing-clock-out notifications for Digital Attendance.';

    public function handle(): int
    {
        $flagged = 0;
        $notified = 0;

        foreach (Account::all() as $account) {
            $settings = digital_attendance_settings($account->id);
            if (! $settings['digital_attendance_enabled']) {
                continue;
            }

            $flagged += $this->flagMissingClockOuts($account, $settings);
            $notified += $this->notifyLongSessions($account, $settings);

            if ($settings['digital_attendance_require_clock_out']) {
                $notified += $this->notifyReminders($account, $settings);
            }
        }

        $this->info("Digital Attendance: flagged {$flagged} missing clock-outs, sent {$notified} notifications.");

        return self::SUCCESS;
    }

    /**
     * A session left CLOCKED_IN from a previous day never got a clock-out —
     * flag it (never fabricate an exit time, per spec) and notify the user.
     */
    private function flagMissingClockOuts(Account $account, array $settings): int
    {
        $count = 0;

        DigitalAttendanceRecord::query()
            ->where('account_id', $account->id)
            ->where('status', DigitalAttendanceRecord::STATUS_CLOCKED_IN)
            ->whereDate('attendance_date', '<', now()->toDateString())
            ->with('user')
            ->chunkById(100, function ($records) use (&$count) {
                foreach ($records as $record) {
                    try {
                        $record->update(['status' => DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT]);

                        DigitalAttendanceEvent::record($record, DigitalAttendanceEvent::ADMIN_CORRECTION, 'Automatically flagged as missing clock-out (session spanned into the next day).', [
                            'old_value' => ['status' => DigitalAttendanceRecord::STATUS_CLOCKED_IN],
                            'new_value' => ['status' => DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT],
                            'actor_id' => null,
                        ]);

                        if ($record->user) {
                            DigitalAttendanceNotifier::once(
                                'missing_clock_out', $record->user, $record, 'Missing Clock-Out',
                                __('You appear to have forgotten to clock out on :date.', ['date' => $record->attendance_date->toFormattedDateString()])
                            );
                        }

                        $count++;
                    } catch (\Throwable $e) {
                        Log::error('digital-attendance:process flagMissingClockOuts failed', ['record_id' => $record->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $count;
    }

    /**
     * Still clocked in and past 12 hours today — a gentle nudge, sent once.
     */
    private function notifyLongSessions(Account $account, array $settings): int
    {
        $count = 0;

        DigitalAttendanceRecord::query()
            ->where('account_id', $account->id)
            ->where('status', DigitalAttendanceRecord::STATUS_CLOCKED_IN)
            ->whereDate('attendance_date', now()->toDateString())
            ->where('clock_in_at', '<=', now()->subHours(12))
            ->with('user')
            ->get()
            ->each(function (DigitalAttendanceRecord $record) use (&$count) {
                if (! $record->user) {
                    return;
                }

                try {
                    if (DigitalAttendanceNotifier::once('long_session', $record->user, $record, 'Long Session', __('You have been clocked in for more than 12 hours.'), ['hours' => '12'])) {
                        $count++;
                    }
                } catch (\Throwable $e) {
                    Log::error('digital-attendance:process notifyLongSessions failed', ['record_id' => $record->id, 'error' => $e->getMessage()]);
                }
            });

        return $count;
    }

    /**
     * Staff who can clock in but haven't yet, once the late threshold has
     * passed for the day — one reminder per user per day.
     */
    private function notifyReminders(Account $account, array $settings): int
    {
        $count = 0;
        $cutoff = now()->setTimeFromTimeString($settings['digital_attendance_working_start'])->addMinutes($settings['digital_attendance_late_threshold_minutes']);

        if (now()->lt($cutoff) || now()->gt($cutoff->copy()->addHours(4))) {
            return 0;
        }

        $users = User::query()
            ->where('account_id', $account->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $u->can('clock-in-digital-attendance'));

        foreach ($users as $user) {
            try {
                $hasToday = DigitalAttendanceRecord::query()
                    ->where('user_id', $user->id)
                    ->whereDate('attendance_date', now()->toDateString())
                    ->exists();

                if ($hasToday) {
                    continue;
                }

                if (DigitalAttendanceNotifier::once('clock_in_reminder', $user, null, 'Digital Attendance Reminder', __("You haven't clocked in today."))) {
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::error('digital-attendance:process notifyReminders failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }
}
