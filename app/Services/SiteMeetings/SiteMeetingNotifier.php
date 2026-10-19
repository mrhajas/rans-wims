<?php

namespace App\Services\SiteMeetings;

use App\Models\SiteMeeting;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\Workflow\WorkflowMessenger;
use App\Services\Workflow\WorkflowSettings;
use App\Support\Workflow\WorkflowModules;
use Illuminate\Support\Facades\DB;

/**
 * Site Meetings notifications. They go through the Site Meetings workflow page (who is told, on which channel and with which words);
 * this class works out the meeting-specific part: which people of the meeting each notice is for. Never notifies the person who did the
 * action.
 */
class SiteMeetingNotifier
{
    public const MODULE = 'site_meetings';

    /** Users involved in a meeting: invited users, chairperson and the person who writes the minutes. */
    public static function involved(SiteMeeting $meeting): array
    {
        $ids = $meeting->attendees()->whereNotNull('user_id')->pluck('user_id')->all();
        foreach ([$meeting->chairperson_id, $meeting->recorder_id] as $id) {
            if ($id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** The people a rule points at for this meeting. */
    public static function recipients(SiteMeeting $meeting, array $rule): array
    {
        $ids = [];
        foreach ($rule['who'] ?? [] as $group) {
            $ids = array_merge($ids, match ($group) {
                'invited' => $meeting->attendees()->whereNotNull('user_id')->pluck('user_id')->all(),
                'chairperson' => [$meeting->chairperson_id],
                'recorder' => [$meeting->recorder_id],
                'preparer' => [$meeting->prepared_by ?: $meeting->created_by],
                'site_team' => DB::table('user_warehouse')->where('warehouse_id', $meeting->warehouse_id)->pluck('user_id')->all(),
                default => [],
            });
        }
        if (! empty($rule['roles'])) {
            $ids = array_merge($ids, User::query()->where('account_id', $meeting->account_id)->role($rule['roles'])->pluck('id')->all());
        }
        $ids = array_merge($ids, $rule['users'] ?? []);

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Hands one event to the Site Meetings workflow page: it decides who is told besides the people of the meeting, on which channels
     * (bell, SMS, e-mail, WhatsApp, Meta) and with which words. "The people of the meeting" are the groups chosen for that notice under
     * "People of the meeting" on the settings page. `$only` narrows the audience to specific people (for example the ones newly
     * invited); `$dedupe` is the duplicate-protection scope (a date for reminders, otherwise unique). Returns how many people were told.
     */
    public static function dispatch(string $event, SiteMeeting $meeting, string $title, string $message, ?User $actor = null, ?array $only = null, ?string $dedupe = null, string $changes = ''): int
    {
        if (! FeatureManager::isEnabled('site_meetings_reminders')) {
            return 0;
        }
        try {
            $accountId = (int) $meeting->account_id;
            $rule = WorkflowSettings::effective(self::MODULE, $accountId, $event);
            if (! $rule['is_enabled'] || ! isset(WorkflowModules::get(self::MODULE)['events'][$event])) {
                return 0;
            }
            if ($only !== null && ! $only) {
                return 0;
            }

            $groups = app(SiteMeetingSettings::class)->forAccount($accountId)->rules()[$event]['who'] ?? [];
            $ids = self::recipients($meeting, ['who' => $groups]);
            if ($only !== null) {
                $ids = array_values(array_intersect($ids, array_map('intval', $only)));
            }
            $meeting->loadMissing('warehouse:id,name');
            $ctx = [
                'account_id' => $accountId, 'title' => $title, 'message' => $message, 'url' => route('site-meetings.show', $meeting->id), 'skip_actor' => true,
                'owner_users' => User::query()->whereIn('id', $ids)->where('account_id', $accountId)->where('is_active', true)->get(),
                'warehouse_ids' => array_filter([$meeting->warehouse_id]), 'dedupe' => $dedupe ?: (string) now()->getPreciseTimestamp(3) . ':' . $event . ':' . $meeting->id,
                'candidates' => $only !== null ? User::query()->whereIn('id', array_map('intval', $only))->get() : null,
                'values' => [
                    'reference' => (string) $meeting->reference, 'meeting' => (string) $meeting->display_title, 'project' => (string) optional($meeting->warehouse)->name,
                    'when' => self::when($meeting), 'venue' => (string) $meeting->venue, 'changes' => $changes,
                ],
            ];
            $def = WorkflowModules::get(self::MODULE)['events'][$event];
            $told = WorkflowMessenger::audience(self::MODULE, $rule, $def, $accountId, 0, $actor, $ctx, $meeting)->count();
            if ($told === 0) {
                return 0;
            }
            WorkflowMessenger::dispatch(self::MODULE, $event, $meeting, $actor, $ctx);

            return $told;
        } catch (\Throwable $e) {
            report($e); // a notification problem must never break scheduling or saving minutes
            return 0;
        }
    }

    private static function when(SiteMeeting $m): string
    {
        $time = SiteMeeting::hm($m->start_time);

        return $m->meeting_date->format('D, d M Y').($time ? ' at '.$time : '');
    }

    private static function where(SiteMeeting $m): string
    {
        $m->loadMissing('warehouse:id,name');

        return $m->display_title.' at '.optional($m->warehouse)->name;
    }

    public static function scheduled(SiteMeeting $meeting, ?array $onlyUsers, ?User $actor): void
    {
        self::dispatch('scheduled', $meeting, 'Site meeting scheduled', self::where($meeting).' - '.self::when($meeting).'.', $actor, $onlyUsers);
    }

    /** `$event` is 'changed' (date / time / site / venue), 'status' or 'deleted'. */
    public static function changed(SiteMeeting $meeting, string $what, ?User $actor, string $event = 'changed'): void
    {
        self::dispatch($event, $meeting, 'Site meeting '.$what, self::where($meeting).' ('.self::when($meeting).') '.$what.'.', $actor);
    }

    public static function restored(SiteMeeting $meeting, ?User $actor): void
    {
        self::dispatch('restored', $meeting, 'Site meeting restored', self::where($meeting).' ('.self::when($meeting).') was restored.', $actor);
    }

    /** Minutes saved (draft) or copied from an earlier meeting; `$what` names the sections or the source. */
    public static function minutesSaved(SiteMeeting $meeting, string $what, ?User $actor): void
    {
        self::dispatch('minutes_saved', $meeting, 'Meeting minutes saved', 'The minutes of '.self::where($meeting).' were saved by '.optional($actor)->name.': '.$what.'.', $actor, null, null, $what);
    }

    /** `$event` is 'attachment_added' or 'attachment_removed'. */
    public static function attachment(SiteMeeting $meeting, string $event, string $files, ?User $actor): void
    {
        $verb = $event === 'attachment_added' ? 'added to' : 'removed from';
        self::dispatch($event, $meeting, 'Meeting attachment '.($event === 'attachment_added' ? 'added' : 'removed'), optional($actor)->name.' '.$verb.' '.self::where($meeting).': '.$files.'.', $actor, null, null, $files);
    }

    /**
     * A change to the Site Meetings settings (there is no meeting involved): told to the roles / people chosen on the workflow page.
     * `$what` is a short description such as "reminders, week layout" or "minutes layout".
     */
    public static function settingsChanged(?User $actor, string $what): void
    {
        try {
            $accountId = (int) (optional($actor)->account_id ?: 1);
            $rule = WorkflowSettings::effective(self::MODULE, $accountId, 'settings_changed');
            if (! $rule['is_enabled']) {
                return;
            }
            WorkflowMessenger::dispatch(self::MODULE, 'settings_changed', null, $actor, [
                'account_id' => $accountId, 'title' => 'Site Meetings settings changed', 'message' => optional($actor)->name.' changed the Site Meetings settings: '.$what.'.',
                'url' => route('site-meetings.settings'), 'skip_actor' => true, 'dedupe' => uniqid('settings:', true),
                'details' => ['By' => optional($actor)->name, 'Changed' => $what, 'At' => now()->format('d M Y H:i')],
                'values' => ['changes' => $what],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function report(SiteMeeting $meeting, string $what, ?User $actor): void
    {
        $event = ['submitted' => 'minutes_submitted', 'finalised' => 'minutes_finalised', 'reopened' => 'minutes_reopened'][$what] ?? 'minutes_submitted';
        self::dispatch($event, $meeting, 'Meeting minutes '.$what, 'The minutes of '.self::where($meeting).' were '.$what.' by '.optional($actor)->name.'.', $actor);
    }

    public static function reminder(SiteMeeting $meeting, string $when): int
    {
        return self::dispatch('reminder', $meeting, 'Site meeting '.$when, self::where($meeting).' - '.self::when($meeting).($meeting->venue ? ', '.$meeting->venue : '').'.', null, null, now()->toDateString().':'.$when);
    }
}
