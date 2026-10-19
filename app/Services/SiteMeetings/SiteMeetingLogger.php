<?php

namespace App\Services\SiteMeetings;

use App\Models\SiteMeeting;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Every Site Meetings audit event goes into the normal WIMS activity log (System Logs), log name "Site Meetings", with
 * the action in `event` and who / where in properties. The same rows feed the history of a meeting: who created it,
 * who looked at it, who changed what, and who printed or downloaded it.
 */
class SiteMeetingLogger
{
    public const LOG_NAME = 'Site Meetings';

    private const VERBS = [
        'MEETING_CREATE' => 'scheduled meeting %r',
        'MEETING_VIEW' => 'viewed meeting %r',
        'MEETING_UPDATE' => 'updated meeting %r',
        'MEETING_MOVE' => 'moved meeting %r to another day',
        'MEETING_STATUS' => 'changed the status of meeting %r',
        'MEETING_DELETE' => 'deleted meeting %r',
        'MEETING_RESTORE' => 'restored meeting %r',
        'ATTACHMENT_ADD' => 'attached "%f" to %r',
        'ATTACHMENT_REMOVE' => 'removed the attachment "%f" from %r',
        'ATTACHMENT_DOWNLOAD' => 'downloaded the attachment "%f" of %r',
        'REPORT_VIEW' => 'opened the minutes of %r',
        'REPORT_START' => 'started the minutes of %r',
        'REPORT_UPDATE' => 'saved the minutes of %r',
        'REPORT_SUBMIT' => 'submitted the minutes of %r',
        'REPORT_FINALISE' => 'finalised the minutes of %r',
        'REPORT_REOPEN' => 'reopened the minutes of %r',
        'MEETING_PRINT' => 'printed meeting %r',
        'MEETING_DOWNLOAD' => 'downloaded meeting %r as PDF',
        'REPORT_PRINT' => 'printed the minutes of %r',
        'REPORT_DOWNLOAD' => 'downloaded the minutes of %r as PDF',
        'WEEK_PRINT' => 'printed the weekly meeting schedule',
        'WEEK_DOWNLOAD' => 'downloaded the weekly meeting schedule as PDF',
        'SETTINGS_CHANGE' => 'changed Site Meetings settings',
        'TEMPLATE_CHANGE' => 'changed the layout of the meeting minutes',
        'TEMPLATE_RESET' => 'reset the layout of the meeting minutes to the WIMS default',
    ];

    public static function log(?User $user, ?SiteMeeting $meeting, string $action, ?string $filename = null, array $extra = []): void
    {
        $request = request();
        $agent = user_agent_info((string) $request->userAgent());
        $text = strtr(self::VERBS[$action] ?? strtolower($action).' %r', ['%r' => $meeting ? '"'.$meeting->reference.'"' : '', '%f' => $filename ?? '']);

        $builder = activity(self::LOG_NAME)->causedBy($user)->event(strtolower($action));
        if ($meeting) {
            $builder->performedOn($meeting);
        }
        $builder->withProperties(array_merge([
            'action' => $action,
            'meeting_reference' => $meeting->reference ?? null,
            'meeting_id' => $meeting->id ?? null,
            'site_id' => $meeting->warehouse_id ?? null,
            'filename' => $filename,
            'ip' => $request->ip(),
            'browser' => $agent['browser'] ?? null,
            'device' => $agent['device'] ?? null,
            'os' => $agent['os'] ?? null,
            'user_agent' => (string) $request->userAgent(),
        ], $extra))->log(trim(($user->name ?? 'System').' '.$text));
    }

    /** A view is written once per person per `$withinMinutes` so refreshing the page does not flood the history. */
    public static function logView(?User $user, SiteMeeting $meeting, string $action, int $withinMinutes = 30): void
    {
        if (! $user) {
            return;
        }
        $recent = Activity::query()->where('log_name', self::LOG_NAME)->where('event', strtolower($action))
            ->where('subject_type', SiteMeeting::class)->where('subject_id', $meeting->id)->where('causer_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes(max(1, $withinMinutes)))->exists();
        if (! $recent) {
            self::log($user, $meeting, $action);
        }
    }

    /** Who last printed / downloaded the minutes (or the meeting sheet) and when — null if never. */
    public static function lastPrinted(SiteMeeting $meeting, array $events = ['report_print', 'report_download']): ?array
    {
        $a = Activity::query()->where('log_name', self::LOG_NAME)->where('subject_type', SiteMeeting::class)->where('subject_id', $meeting->id)
            ->whereIn('event', $events)->with('causer:id,name')->orderByDesc('id')->first();

        return $a ? ['user' => $a->causer->name ?? 'System', 'at' => $a->created_at->toIso8601String(), 'download' => str_ends_with($a->event, 'download')] : null;
    }

    /** Newest first. */
    public static function timeline(SiteMeeting $meeting, int $limit = 200): array
    {
        return Activity::query()
            ->where('log_name', self::LOG_NAME)->where('subject_type', SiteMeeting::class)->where('subject_id', $meeting->id)
            ->with('causer:id,name')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'action' => $a->properties['action'] ?? strtoupper((string) $a->event),
                'description' => $a->description,
                'user' => $a->causer->name ?? 'System',
                'user_id' => $a->causer_id,
                'filename' => $a->properties['filename'] ?? null,
                'changes' => $a->properties['changes'] ?? null,
                'ip' => $a->properties['ip'] ?? null,
                'device' => $a->properties['device'] ?? null,
                'at' => $a->created_at->toIso8601String(),
            ])->all();
    }

    /** Who has opened the meeting or its minutes: [[id, name, views, last_at]] most recent first. */
    public static function viewers(SiteMeeting $meeting): array
    {
        $rows = Activity::query()
            ->where('log_name', self::LOG_NAME)->whereIn('event', ['meeting_view', 'report_view'])
            ->where('subject_type', SiteMeeting::class)->where('subject_id', $meeting->id)->whereNotNull('causer_id')
            ->groupBy('causer_id')->selectRaw('causer_id, COUNT(*) as views, MAX(created_at) as last_at')->get();
        $names = User::withTrashed()->whereIn('id', $rows->pluck('causer_id'))->pluck('name', 'id');

        return $rows->sortByDesc('last_at')->map(fn ($r) => [
            'id' => (int) $r->causer_id,
            'name' => $names[$r->causer_id] ?? 'User',
            'views' => (int) $r->views,
            'last_at' => \Illuminate\Support\Carbon::parse($r->last_at)->toIso8601String(),
        ])->values()->all();
    }
}
