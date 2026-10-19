<?php

namespace App\Services\SiteDiary;

use App\Models\SiteDiary;
use App\Models\SiteDiaryNotificationRule as Rule;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;
use App\Services\Workflow\WorkflowSettings;
use App\Support\Workflow\WorkflowModules;
use Illuminate\Support\Facades\Log;

/**
 * Hands Site Diary events to the Site Diary workflow page (who is told, on which channels, with which words). Never breaks the action.
 * Nobody is told about a diary they could not open. The time of the daily "diary missing" message stays on the Site Diary settings page.
 */
class SiteDiaryNotifier
{
    public const MODULE = 'site_diary';

    /** The workflow event a legacy rule key stands for. */
    public static function eventKey(string $ruleKey): string
    {
        return strtolower($ruleKey);
    }

    public static function event(string $key, ?User $actor, ?SiteDiary $diary, array $ctx = []): void
    {
        try {
            $accountId = (int) ($ctx['account_id'] ?? (optional($actor)->account_id ?: 1));
            $event = self::eventKey($key);
            $rule = WorkflowSettings::effective(self::MODULE, $accountId, $event);
            if (! $rule['is_enabled']) {
                return;
            }
            [$title, $message] = self::compose($key, $actor, $diary, $ctx);
            $url = $diary && ! $diary->trashed() && $key !== Rule::DRIVE_UPLOAD_FAILED ? route('site-diary.show', $diary->id)
                : (in_array($key, [Rule::DRIVE_UPLOAD_FAILED, Rule::SETTINGS_CHANGED], true) ? route('site-diary.settings') : route('site-diary.index', $diary ? ['trashed' => 'only'] : []));
            $details = array_filter([
                'Project' => optional(optional($diary)->warehouse)->name,
                'Diary' => optional($diary)->reference,
                'Date' => optional(optional($diary)->diary_date)->format('d M Y'),
                'By' => optional($actor)->name,
            ]);

            $send = [
                'account_id' => $accountId, 'title' => $title, 'message' => $message, 'url' => $url, 'details' => $details, 'skip_actor' => true,
                'dedupe' => uniqid($event . ':', true),
                'warehouse_ids' => $diary && $diary->warehouse_id ? [$diary->warehouse_id] : null,
                'values' => [
                    'reference' => (string) optional($diary)->reference, 'project' => (string) optional(optional($diary)->warehouse)->name,
                    'date' => (string) optional(optional($diary)->diary_date)->format('d M Y'), 'count' => (string) ($ctx['count'] ?? ''),
                    'changes' => (string) ($ctx['changes'] ?? ''), 'reason' => (string) ($ctx['reason'] ?? ''), 'filename' => (string) ($ctx['filename'] ?? ''),
                ],
            ];

            // Nobody is told about a diary they could not open (a failed Drive upload is a system matter for the people chosen, so it is not held back by the diary's draft state).
            if ($diary && $key !== Rule::DRIVE_UPLOAD_FAILED) {
                $def = WorkflowModules::get(self::MODULE)['events'][$event];
                $people = WorkflowMessenger::audience(self::MODULE, $rule, $def, $accountId, 0, $actor, $send, $diary)->filter(fn (User $u) => $diary->isVisibleTo($u))->values();
                if ($people->isEmpty()) {
                    return;
                }
                $send['candidates'] = $people;
            }

            WorkflowMessenger::dispatch(self::MODULE, $event, $diary, $actor, $send);
        } catch (\Throwable $e) {
            Log::warning('Site Diary notification failed', ['event' => $key, 'error' => $e->getMessage()]);
        }
    }

    /** Is the daily "diary missing" message switched on on the Site Diary workflow page? */
    public static function missingEnabled(int $accountId): bool
    {
        try {
            return (bool) WorkflowSettings::effective(self::MODULE, $accountId ?: 1, 'missing_diary')['is_enabled'];
        } catch (\Throwable) {
            return false;
        }
    }

    /** Daily "no diary yet" message for the projects that require one. Returns how many people were told. */
    public static function missingDigest(int $accountId, array $schedule, array $projects, string $date): int
    {
        if (! $projects || ! self::missingEnabled($accountId)) {
            return 0;
        }
        $rule = WorkflowSettings::effective(self::MODULE, $accountId, 'missing_diary');
        $def = WorkflowModules::get(self::MODULE)['events']['missing_diary'];
        $names = collect($projects)->pluck('name');
        $list = $names->take(6)->implode(', ') . ($names->count() > 6 ? '…' : '');

        $people = WorkflowMessenger::audience(self::MODULE, $rule, $def, $accountId, 0, null, [], null);
        if ($people->isNotEmpty()) {
            WorkflowMessenger::dispatch(self::MODULE, 'missing_diary', null, null, [
                'account_id' => $accountId, 'candidates' => $people,
                'title' => __('Site Diary missing for :n project(s)', ['n' => count($projects)]),
                'message' => __('No Site Diary was recorded on :d for: :p', ['d' => $date, 'p' => $list]),
                'url' => route('site-diary.index', ['date_to' => $date]),
                'details' => ['Date' => $date, 'Projects without a diary' => (string) count($projects)],
                'dedupe' => 'missing:' . $date,
                'values' => ['date' => $date, 'projects' => $list, 'projectscount' => (string) count($projects)],
            ]);
        }

        return $people->count();
    }

    private static function compose(string $key, ?User $actor, ?SiteDiary $diary, array $ctx): array
    {
        $who = optional($actor)->name ?? __('Someone');
        $project = optional(optional($diary)->warehouse)->name ?? '';
        $ref = optional($diary)->reference;

        return match ($key) {
            Rule::NEW_DIARY, Rule::NEW_ASSIGNED_PROJECT => [__('New Site Diary'), __(':w recorded :r for :p.', ['w' => $who, 'r' => $ref, 'p' => $project])],
            Rule::NEW_PHOTOS => [__('New site photos'), __(':w added :n photo(s) to :r (:p).', ['w' => $who, 'n' => $ctx['count'] ?? 1, 'r' => $ref, 'p' => $project])],
            Rule::NEW_VIDEOS => [__('New site videos'), __(':w added :n video(s) to :r (:p).', ['w' => $who, 'n' => $ctx['count'] ?? 1, 'r' => $ref, 'p' => $project])],
            Rule::DIARY_UPDATED => [__('Site Diary edited'), __(':w edited :r (:p): :c.', ['w' => $who, 'r' => $ref, 'p' => $project, 'c' => $ctx['changes'] ?? ''])],
            Rule::DIARY_DELETED => [__('Site Diary deleted'), __(':w deleted :r (:p). Reason: :x', ['w' => $who, 'r' => $ref, 'p' => $project, 'x' => $ctx['reason'] ?? ''])],
            Rule::DIARY_RESTORED => [__('Site Diary restored'), __(':w restored :r (:p).', ['w' => $who, 'r' => $ref, 'p' => $project])],
            Rule::MEDIA_REMOVED => [__('Site Diary file removed'), __(':w removed “:f” from :r (:p).', ['w' => $who, 'f' => $ctx['filename'] ?? '', 'r' => $ref, 'p' => $project])],
            Rule::DRIVE_UPLOAD_FAILED => [__('Google Drive upload failed'), __('“:f” of :r (:p) could not be sent to Google Drive: :c', ['f' => $ctx['filename'] ?? '', 'r' => $ref, 'p' => $project, 'c' => $ctx['changes'] ?? ''])],
            Rule::SETTINGS_CHANGED => [__('Site Diary settings changed'), __(':w changed the Site Diary settings: :c.', ['w' => $who, 'c' => $ctx['changes'] ?? ''])],
            default => [__('Site Diary'), $key],
        };
    }
}
