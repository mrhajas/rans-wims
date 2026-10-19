<?php

namespace App\Services\SiteDiary;

use App\Models\SiteDiary;
use App\Models\SiteDiaryMedia;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Every Site Diary audit event goes into the normal WIMS activity log (System Logs), log name "Site Diary", with the
 * action in `event` and who / where in properties. The same rows feed the history timeline of a diary.
 */
class SiteDiaryLogger
{
    public const LOG_NAME = 'Site Diary';

    private const VERBS = [
        'DIARY_CREATE' => 'created diary %r',
        'DIARY_SUBMIT' => 'submitted diary %r (first attachment saved)',
        'DIARY_VIEW' => 'viewed diary %r',
        'DIARY_UPDATE' => 'updated diary %r',
        'DIARY_DELETE' => 'deleted diary %r',
        'DIARY_RESTORE' => 'restored diary %r',
        'PHOTO_ADD' => 'added photo "%f" to %r',
        'PHOTO_VIEW' => 'viewed photo "%f"',
        'PHOTO_OPEN_NEW_TAB' => 'opened photo "%f" in a new tab',
        'PHOTO_DOWNLOAD' => 'downloaded photo "%f"',
        'PHOTO_DELETE' => 'removed photo "%f" from %r',
        'VIDEO_ADD' => 'added video "%f" to %r',
        'VIDEO_VIEW' => 'played video "%f"',
        'VIDEO_OPEN_NEW_TAB' => 'opened video "%f" in a new tab',
        'VIDEO_DOWNLOAD' => 'downloaded video "%f"',
        'VIDEO_DELETE' => 'removed video "%f" from %r',
        'FILE_PURGED' => 'deleted the file "%f" from storage (Google Drive Trash / WIMS server)',
        'FILE_PURGE_FAILED' => 'could not delete the file "%f" from storage yet, it will be retried',
        'FILE_EXPIRED' => 'erased the file "%f" from Google Drive (retention period reached)',
        'DRIVE_CLEARED' => 'cleared Google Drive (every Site Diary file inside the storage folder was erased)',
        'DRIVE_UPLOAD' => 'uploaded "%f" to Google Drive',
        'DRIVE_UPLOAD_FAILED' => 'could not upload "%f" to Google Drive',
        'DRIVE_RETRY' => 'retried the Google Drive upload of "%f"',
        'SETTINGS_CHANGE' => 'changed Site Diary settings',
        'REPORT_GENERATED' => 'generated a Site Diary report',
        'REPORT_PRINTED' => 'printed a Site Diary report',
        'REPORT_DOWNLOADED' => 'downloaded a Site Diary report',
        'EXPORT_GENERATED' => 'exported a Site Diary report',
    ];

    /** Actions that mean "somebody looked at / took a file" (the access report). */
    public const ACCESS_ACTIONS = ['DIARY_VIEW', 'PHOTO_VIEW', 'PHOTO_OPEN_NEW_TAB', 'PHOTO_DOWNLOAD', 'VIDEO_VIEW', 'VIDEO_OPEN_NEW_TAB', 'VIDEO_DOWNLOAD'];

    public static function log(?User $user, ?SiteDiary $diary, string $action, ?SiteDiaryMedia $media = null, array $extra = []): void
    {
        $request = request();
        $agent = user_agent_info((string) $request->userAgent());
        $text = strtr(self::VERBS[$action] ?? strtolower($action).' %r', ['%r' => $diary ? '"'.$diary->reference.'"' : '', '%f' => $media->original_name ?? '']);

        $builder = activity(self::LOG_NAME)->causedBy($user)->event(strtolower($action));
        if ($diary) {
            $builder->performedOn($diary);
        }
        $builder->withProperties(array_merge([
            'action' => $action,
            'diary_reference' => $diary->reference ?? null,
            'diary_id' => $diary->id ?? null,
            'project_id' => $diary->warehouse_id ?? null,
            'media_id' => $media->id ?? null,
            'media_type' => $media->media_type ?? null,
            'filename' => $media->original_name ?? null,
            'ip' => $request->ip(),
            'browser' => $agent['browser'] ?? null,
            'device' => $agent['device'] ?? null,
            'os' => $agent['os'] ?? null,
            'user_agent' => (string) $request->userAgent(),
        ], $extra))->log(trim(($user->name ?? 'System').' '.$text));
    }

    /** Timeline of a diary, newest first; runs of "added photo/video" by one person within 15 minutes become one line. */
    public static function timeline(SiteDiary $diary, int $limit = 300): array
    {
        $rows = Activity::query()
            ->where('log_name', self::LOG_NAME)->where('subject_type', SiteDiary::class)->where('subject_id', $diary->id)
            ->with('causer:id,name')->orderByDesc('id')->limit($limit)->get();

        $out = [];
        foreach ($rows as $a) {
            $action = $a->properties['action'] ?? strtoupper((string) $a->event);
            $type = in_array($action, ['PHOTO_ADD', 'VIDEO_ADD'], true) ? $action : null;
            $lastKey = array_key_last($out);
            $last = $lastKey === null ? null : $out[$lastKey];
            if ($type && $last && ($last['group'] ?? null) === $type && $last['user_id'] === $a->causer_id
                && abs(strtotime($last['at']) - strtotime((string) $a->created_at)) <= 900) {
                $out[$lastKey]['count']++;
                $out[$lastKey]['description'] = self::groupText($out[$lastKey]['user'], $type, $out[$lastKey]['count']);
                continue;
            }
            $out[] = [
                'id' => $a->id,
                'action' => $action,
                'group' => $type,
                'count' => 1,
                'description' => $a->description,
                'user' => $a->causer->name ?? 'System',
                'user_id' => $a->causer_id,
                'filename' => $a->properties['filename'] ?? null,
                'ip' => $a->properties['ip'] ?? null,
                'device' => $a->properties['device'] ?? null,
                'at' => $a->created_at->toIso8601String(),
            ];
        }

        return $out;
    }

    private static function groupText(string $user, string $type, int $n): string
    {
        $noun = $type === 'PHOTO_ADD' ? 'photo' : 'video';

        return "{$user} added {$n} {$noun}".($n === 1 ? '' : 's');
    }

    /**
     * Who has opened these diaries: [diary_id => [[id, name, views, last_at], …]] (most recent first). Taken from the same
     * activity log rows as the history, in one grouped query, so a list page never loops per row.
     */
    public static function viewersFor(array $diaryIds, array $exceptUserIdByDiary = []): array
    {
        if (! $diaryIds) {
            return [];
        }
        $rows = Activity::query()
            ->where('log_name', self::LOG_NAME)->where('event', 'diary_view')->where('subject_type', SiteDiary::class)
            ->whereIn('subject_id', $diaryIds)->whereNotNull('causer_id')
            ->groupBy('subject_id', 'causer_id')
            ->selectRaw('subject_id, causer_id, COUNT(*) as views, MAX(created_at) as last_at')
            ->get();
        $names = \App\Models\User::withTrashed()->whereIn('id', $rows->pluck('causer_id')->unique())->pluck('name', 'id');

        $out = [];
        foreach ($rows->sortByDesc('last_at') as $r) {
            if (($exceptUserIdByDiary[$r->subject_id] ?? null) === (int) $r->causer_id) {
                continue;
            }
            $out[$r->subject_id][] = ['id' => (int) $r->causer_id, 'name' => $names[$r->causer_id] ?? 'User', 'views' => (int) $r->views, 'last_at' => \Carbon\Carbon::parse($r->last_at)->toIso8601String()];
        }

        return $out;
    }
}
