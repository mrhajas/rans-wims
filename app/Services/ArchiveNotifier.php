<?php

namespace App\Services;

use App\Models\ArchiveNotificationRule;
use App\Models\ArchiveRecord;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;
use App\Services\Workflow\WorkflowSettings;
use App\Support\Workflow\WorkflowModules;
use Illuminate\Support\Facades\Log;

/**
 * Hands Archive events to the Archive workflow page (who is told, on which channels, with which words). It never lets a notification
 * problem break the action that triggered it, and it never tells someone about a Confidential / Restricted record they are not allowed
 * to see. The time and the "expiring within" window of the daily summary stay on the Archive Notifications page (ArchiveNotificationRule).
 */
class ArchiveNotifier
{
    public const MODULE = 'archive';

    public static function event(string $key, ?User $actor, ?ArchiveRecord $record = null, array $ctx = []): void
    {
        try {
            $accountId = (int) (optional($actor)->account_id ?: 1);
            $rule = WorkflowSettings::effective(self::MODULE, $accountId, $key);
            if (! $rule['is_enabled']) {
                return;
            }

            [$title, $message, $url, $details] = self::compose($key, $actor, $record, $ctx);

            $send = [
                'account_id' => $accountId, 'title' => $title, 'message' => $message, 'url' => $url, 'details' => $details, 'skip_actor' => true,
                'dedupe' => uniqid($key . ':', true),
                'values' => [
                    'reference' => (string) optional($record)->reference, 'category' => (string) optional($record)->category, 'confidentiality' => (string) optional($record)->confidentiality,
                    'filename' => $ctx['filename'] ?? '', 'folder' => $ctx['folder'] ?? '', 'from' => $ctx['from'] ?? '', 'to' => $ctx['to'] ?? '', 'misses' => $ctx['misses'] ?? '',
                    'changes' => $ctx['changes'] ?? '', 'sendtime' => $ctx['send_time'] ?? '', 'days' => $ctx['days'] ?? '',
                ],
            ];

            // Nobody is told about a record they could not open.
            if ($record) {
                $def = WorkflowModules::get(self::MODULE)['events'][$key];
                $people = WorkflowMessenger::audience(self::MODULE, $rule, $def, $accountId, 0, $actor, ['skip_actor' => true], $record)->filter(fn (User $u) => $record->isAccessibleBy($u))->values();
                if ($people->isEmpty()) {
                    return;
                }
                $send['candidates'] = $people;
            }

            WorkflowMessenger::dispatch(self::MODULE, $key, $record, $actor, $send);
        } catch (\Throwable $e) {
            Log::warning('Archive notification failed', ['event' => $key, 'error' => $e->getMessage()]);
        }
    }

    /** Is the daily summary switched on on the Archive workflow page? (the command asks before it does any work) */
    public static function digestEnabled(?int $accountId): bool
    {
        try {
            return (bool) WorkflowSettings::effective(self::MODULE, (int) ($accountId ?: 1), ArchiveNotificationRule::EXPIRY_DIGEST)['is_enabled'];
        } catch (\Throwable) {
            return false;
        }
    }

    /** The scheduled summary of expiring / expired documents (called by archive:notify-expiry). Returns how many people were told. */
    public static function expiryDigest(?int $accountId, array $schedule): int
    {
        $accountId = (int) ($accountId ?: 1);
        $key = ArchiveNotificationRule::EXPIRY_DIGEST;
        $rule = WorkflowSettings::effective(self::MODULE, $accountId, $key);
        if (! $rule['is_enabled']) {
            return 0;
        }
        $def = WorkflowModules::get(self::MODULE)['events'][$key];
        $lead = max((int) ($schedule['lead_days'] ?? 30), 1);
        $sent = 0;

        WorkflowMessenger::audience(self::MODULE, $rule, $def, $accountId, 0, null, [], null)->each(function (User $u) use ($accountId, $key, $lead, &$sent) {
            $base = fn () => ArchiveRecord::query()->visibleTo($u);
            $expiring = $base()->whereBetween('archive_records.expiry_date', [now()->toDateString(), now()->addDays($lead)->toDateString()]);
            $expired = $base()->where('archive_records.expiry_date', '<', now()->toDateString());
            $nExpiring = (clone $expiring)->count();
            $nExpired = (clone $expired)->count();
            if ($nExpiring + $nExpired === 0) {
                return;
            }

            $items = (clone $expiring)->orderBy('expiry_date')->limit(5)->get(['reference', 'title', 'expiry_date', 'confidentiality'])
                ->map(fn ($r) => ['code' => $r->reference, 'name' => $r->is_restricted ? 'Confidential record' : $r->title, 'quantity' => 'expires ' . optional($r->expiry_date)->format('d M Y'), 'unit' => ''])->all();

            WorkflowMessenger::dispatch(self::MODULE, $key, null, null, [
                'account_id' => $accountId, 'candidates' => collect([$u]),
                'title' => __('Archive: :a expiring, :b expired', ['a' => $nExpiring, 'b' => $nExpired]),
                'message' => __(':a document(s) expire within :d days and :b have already expired.', ['a' => $nExpiring, 'b' => $nExpired, 'd' => $lead]),
                'url' => route('archive.records.index', ['expiry' => $nExpired ? 'expired' : 'expiring']),
                'details' => ['Expiring soon' => (string) $nExpiring, 'Already expired' => (string) $nExpired, 'Window' => $lead . ' days'],
                'items' => $items, 'dedupe' => 'expiry:' . now()->toDateString() . ':' . $u->id,
                'values' => ['expiring' => (string) $nExpiring, 'expired' => (string) $nExpired, 'days' => (string) $lead],
            ]);
            $sent++;
        });

        return $sent;
    }

    private static function compose(string $key, ?User $actor, ?ArchiveRecord $record, array $ctx): array
    {
        $who = optional($actor)->name ?? __('Someone');
        $ref = optional($record)->reference;
        // A restricted record's title is never put in a notification.
        $label = $record ? ($record->is_restricted ? $ref : $ref . ' - ' . $record->title) : '';
        $url = $record && ! $record->trashed() ? route('archive.records.show', $record->id) : route('archive.records.index');
        $details = array_filter([
            'Record' => $ref,
            'Category' => optional($record)->category,
            'Confidentiality' => optional($record)->confidentiality,
            'By' => $who,
            'At' => now()->format('d M Y H:i'),
            'File' => $ctx['filename'] ?? null,
        ]);

        return match ($key) {
            ArchiveNotificationRule::RESTRICTED_RECORD_CREATED => [__('Confidential record created'), __(':w created :r (:c).', ['w' => $who, 'r' => $ref, 'c' => optional($record)->confidentiality]), $url, $details],
            ArchiveNotificationRule::RECORD_CREATED => [__('New Archive record'), __(':w created :r.', ['w' => $who, 'r' => $label]), $url, $details],
            ArchiveNotificationRule::CONFIDENTIALITY_CHANGED => [__('Confidentiality changed'), __(':w changed :r from :a to :b.', ['w' => $who, 'r' => $ref, 'a' => $ctx['from'] ?? '?', 'b' => $ctx['to'] ?? '?']), $url, $details + ['Changed' => ($ctx['from'] ?? '?') . ' → ' . ($ctx['to'] ?? '?')]],
            ArchiveNotificationRule::RESTRICTED_DOWNLOADED => [__('Confidential document downloaded'), __(':w downloaded “:f” from :r.', ['w' => $who, 'f' => $ctx['filename'] ?? '', 'r' => $ref]), $url, $details],
            ArchiveNotificationRule::RESTRICTED_OPENED => [__('Confidential document opened'), __(':w opened “:f” from :r.', ['w' => $who, 'f' => $ctx['filename'] ?? '', 'r' => $ref]), $url, $details],
            ArchiveNotificationRule::RECORD_DELETED => [__('Archive record deleted'), __(':w deleted :r.', ['w' => $who, 'r' => $label]), route('archive.records.index', ['trashed' => 'only']), $details],
            ArchiveNotificationRule::FOLDER_UNLOCKED => [__('Restricted folder unlocked'), __(':w unlocked “:f”.', ['w' => $who, 'f' => $ctx['folder'] ?? '']), route('archive.records.index'), ['By' => $who, 'Folder' => $ctx['folder'] ?? '', 'At' => now()->format('d M Y H:i')]],
            ArchiveNotificationRule::FAILED_UNLOCK => [__('Failed Archive unlock attempts'), __(':w entered a wrong password :n times in 15 minutes.', ['w' => $who, 'n' => $ctx['misses'] ?? 3]), route('activity', ['search' => 'Archive']), ['User' => $who, 'Wrong passwords' => (string) ($ctx['misses'] ?? 3), 'At' => now()->format('d M Y H:i')]],
            ArchiveNotificationRule::RECORD_UPDATED => [__('Archive record updated'), __(':w updated :r (:c).', ['w' => $who, 'r' => $label, 'c' => $ctx['changes'] ?? '']), $url, $details + ['Changed' => $ctx['changes'] ?? '']],
            ArchiveNotificationRule::RECORD_RESTORED => [__('Archive record restored'), __(':w restored :r.', ['w' => $who, 'r' => $label]), $url, $details],
            ArchiveNotificationRule::FILE_ADDED => [__('File added to Archive record'), __(':w added “:f” to :r.', ['w' => $who, 'f' => $ctx['filename'] ?? '', 'r' => $label]), $url, $details],
            ArchiveNotificationRule::FILE_REMOVED => [__('File removed from Archive record'), __(':w removed “:f” from :r.', ['w' => $who, 'f' => $ctx['filename'] ?? '', 'r' => $label]), $url, $details],
            ArchiveNotificationRule::SCHEDULE_CHANGED => [__('Archive expiry summary schedule changed'), __(':w set the daily expiry summary to :t, looking :d days ahead.', ['w' => $who, 't' => $ctx['send_time'] ?? '', 'd' => $ctx['days'] ?? '']), route('archive.notifications'), ['By' => $who, 'Time' => $ctx['send_time'] ?? '', 'Days ahead' => (string) ($ctx['days'] ?? ''), 'At' => now()->format('d M Y H:i')]],
            default => [__('Archive'), $key, route('archive.dashboard'), $details],
        };
    }
}
