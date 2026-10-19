<?php

namespace App\Services;

use App\Models\DigitalAttendanceRecord;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;
use App\Services\Workflow\WorkflowSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hands Digital Attendance notices to the Digital Attendance workflow page (who is told, on which channels, with which words).
 * Events: missing_clock_out, long_session, clock_in_reminder, buzz. By default each notice goes to the person whose attendance it is.
 * The scheduled notices (missing clock-out, long session, reminder) are sent once per record / person / day; that stays here.
 */
class DigitalAttendanceNotifier
{
    public const MODULE = 'digital_attendance';

    public static function enabled(User $user, string $event): bool
    {
        try {
            return (bool) WorkflowSettings::effective(self::MODULE, (int) ($user->account_id ?: 1), $event)['is_enabled'];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One of the scheduled notices, at most once per record (or per person and day for the reminder). Returns true when it was handed to
     * the workflow engine (and the record is now marked as told), false when it was skipped.
     */
    public static function once(string $event, User $user, ?DigitalAttendanceRecord $record, string $title, string $message, array $values = []): bool
    {
        try {
            if (! self::enabled($user, $event)) {
                return false;
            }
            $key = $record ? 'DA-' . $record->id : 'DAY-' . now()->toDateString();
            $legacyType = ['missing_clock_out' => 'missing_clock_out', 'long_session' => 'long_session', 'clock_in_reminder' => 'reminder'][$event];

            // told before? (older notices carry attendance_type + record id; newer ones carry the workflow type + reference)
            $told = $user->notifications()->where(function ($q) use ($event, $legacyType, $key, $record) {
                $q->where(function ($w) use ($event, $key) {
                    $w->where('data->type', self::MODULE . '_' . $event)->where('data->reference', $key);
                });
                if ($record) {
                    $q->orWhere(fn ($w) => $w->where('data->attendance_type', $legacyType)->where('data->digital_attendance_record_id', $record->id));
                } else {
                    $q->orWhere(fn ($w) => $w->where('data->attendance_type', $legacyType)->whereDate('created_at', now()->toDateString()));
                }
            })->exists();
            // a bell switched off leaves no row, so the guard is also kept for a while in the cache
            if ($told || ! Cache::add('da-once:' . $event . ':' . $user->id . ':' . $key, 1, now()->addDays(40))) {
                return false;
            }

            return self::fire($event, $user, null, $record, $title, $message, $values + ['reference' => $key], 'once:' . $event . ':' . $key . ':' . $user->id);
        } catch (\Throwable $e) {
            Log::error('Digital Attendance notification failed', ['event' => $event, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** An admin's quick message to somebody who is online. */
    public static function buzz(User $sender, User $target, string $message): bool
    {
        return self::fire('buzz', $target, $sender, null, __(':name buzzed you', ['name' => $sender->name]), $message, [], uniqid('buzz:', true), route('dashboard'));
    }

    /**
     * What an administrator may want to be told about somebody's attendance (clock-in / out, late, outside the area, a correction, a
     * void). $subject is the person the record belongs to; they count as "the owner" only when $ownerToo (correction, void).
     */
    public static function report(string $event, ?DigitalAttendanceRecord $record, User $subject, ?User $actor, string $title, string $message, array $values = [], bool $ownerToo = false): bool
    {
        $date = $record && $record->attendance_date ? $record->attendance_date->format('d M Y') : now()->format('d M Y');

        return WorkflowMessenger::dispatch(self::MODULE, $event, $record, $actor ?? $subject, [
            'account_id' => (int) ($subject->account_id ?: 1), 'title' => $title, 'message' => $message, 'url' => route('digital-attendance.show'),
            'owner_user_id' => $ownerToo ? (int) $subject->id : 0, 'dedupe' => 'report:' . $event . ':' . ($record?->id ?? $subject->id) . ':' . intdiv(time(), 20),
            'values' => $values + ['user' => (string) $subject->name, 'date' => $date, 'time' => now()->format('H:i')],
            'details' => array_filter([__('Person') => $subject->name, __('Date') => $date]),
        ]);
    }

    private static function fire(string $event, User $user, ?User $actor, ?DigitalAttendanceRecord $record, string $title, string $message, array $values, string $dedupe, ?string $url = null): bool
    {
        return WorkflowMessenger::dispatch(self::MODULE, $event, $record, $actor, [
            'account_id' => (int) ($user->account_id ?: 1), 'title' => $title, 'message' => $message, 'url' => $url ?? route('digital-attendance.show'),
            'owner_user_id' => (int) $user->id, 'dedupe' => $dedupe, 'values' => $values + ['date' => $record ? $record->attendance_date->format('d M Y') : now()->format('d M Y')],
            'details' => $record ? ['Date' => $record->attendance_date->format('d M Y')] : null,
        ]);
    }
}
