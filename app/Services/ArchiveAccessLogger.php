<?php

namespace App\Services;

use App\Models\ArchiveFile;
use App\Models\ArchiveRecord;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Every Archive audit event goes into the normal WIMS activity log (System Logs), log name "Archive", with the
 * action in the `event` column and the who/where in properties — so there is no separate logging system.
 */
class ArchiveAccessLogger
{
    public const LOG_NAME = 'Archive';

    /** Actions that mean "somebody looked at / took a document" — what the access history and dashboard list. */
    public const ACCESS_ACTIONS = ['VIEW', 'PREVIEW', 'OPEN_NEW_TAB', 'DOWNLOAD', 'PRINT'];

    private const VERBS = [
        'CREATE' => 'created record %r',
        'UPDATE' => 'updated record %r',
        'DELETE' => 'deleted record %r',
        'RESTORE' => 'restored record %r',
        'UPLOAD' => 'uploaded "%f" to %r',
        'FILE_DELETE' => 'removed "%f" from %r',
        'VIEW' => 'viewed record %r',
        'PREVIEW' => 'previewed "%f"',
        'OPEN_NEW_TAB' => 'opened "%f" in a new tab',
        'DOWNLOAD' => 'downloaded "%f"',
        'PRINT' => 'printed record sheet %r',
        'CATEGORY_CHANGE' => 'changed the category of %r',
        'PROJECT_CHANGE' => 'changed the project of %r',
        'CONFIDENTIALITY_CHANGE' => 'changed the confidentiality of %r',
        'RETENTION_CHANGE' => 'changed the expiry date of %r',
        'FOLDER_UNLOCK' => 'unlocked a restricted folder',
        'FAILED_REAUTH' => 'entered a wrong password for a restricted folder',
    ];

    public static function log(?User $user, ArchiveRecord $record, string $action, ?ArchiveFile $file = null, array $extra = []): void
    {
        $request = request();
        $agent = user_agent_info((string) $request->userAgent());
        $name = $user->name ?? 'System';
        $text = strtr(self::VERBS[$action] ?? strtolower($action) . ' %r', [
            '%r' => '"' . $record->reference . '"',
            '%f' => $file->original_name ?? '',
        ]);

        activity(self::LOG_NAME)
            ->performedOn($record)
            ->causedBy($user)
            ->event(strtolower($action))
            ->withProperties(array_merge([
                'action' => $action,
                'archive_reference' => $record->reference,
                'archive_record_id' => $record->id,
                'attachment_id' => $file->id ?? null,
                'filename' => $file->original_name ?? null,
                'ip' => $request->ip(),
                'browser' => $agent['browser'] ?? null,
                'device' => $agent['device'] ?? null,
                'os' => $agent['os'] ?? null,
                'user_agent' => (string) $request->userAgent(),
            ], $extra))
            ->log($name . ' ' . $text);
    }

    /** Events that belong to no single record (folder unlock attempts in the Archive Tree). */
    public static function logEvent(?User $user, string $action, array $extra = []): void
    {
        $request = request();
        $agent = user_agent_info((string) $request->userAgent());

        activity(self::LOG_NAME)
            ->causedBy($user)
            ->event(strtolower($action))
            ->withProperties(array_merge([
                'action' => $action,
                'ip' => $request->ip(),
                'browser' => $agent['browser'] ?? null,
                'device' => $agent['device'] ?? null,
                'os' => $agent['os'] ?? null,
                'user_agent' => (string) $request->userAgent(),
            ], $extra))
            ->log(($user->name ?? 'System') . ' ' . (self::VERBS[$action] ?? strtolower($action)) . (isset($extra['folder']) ? ' "' . $extra['folder'] . '"' : ''));
    }

    /** Access history of one record: who did what to which file, newest first. */
    public static function accessHistory(ArchiveRecord $record, int $limit = 100): array
    {
        return Activity::query()
            ->where('log_name', self::LOG_NAME)
            ->where('subject_type', ArchiveRecord::class)
            ->where('subject_id', $record->id)
            ->whereIn('event', array_map('strtolower', self::ACCESS_ACTIONS))
            ->with('causer:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($a) => self::present($a))
            ->all();
    }

    /** Change history (create / update / upload / delete …) — everything that is not plain access. */
    public static function changeHistory(ArchiveRecord $record, int $limit = 100): array
    {
        return Activity::query()
            ->where('log_name', self::LOG_NAME)
            ->where('subject_type', ArchiveRecord::class)
            ->where('subject_id', $record->id)
            ->whereNotIn('event', array_map('strtolower', self::ACCESS_ACTIONS))
            ->with('causer:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($a) => self::present($a))
            ->all();
    }

    private static function present(Activity $a): array
    {
        return [
            'id' => $a->id,
            'action' => $a->properties['action'] ?? strtoupper((string) $a->event),
            'description' => $a->description,
            'user' => $a->causer?->name ?? 'System',
            'filename' => $a->properties['filename'] ?? null,
            'ip' => $a->properties['ip'] ?? null,
            'browser' => $a->properties['browser'] ?? null,
            'device' => $a->properties['device'] ?? null,
            'at' => $a->created_at?->toIso8601String(),
        ];
    }
}
