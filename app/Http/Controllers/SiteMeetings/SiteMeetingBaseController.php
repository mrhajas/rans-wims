<?php

namespace App\Http\Controllers\SiteMeetings;

use App\Http\Controllers\Controller;
use App\Models\SiteMeeting;
use App\Models\SiteMeetingAttendee;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SiteMeetings\SiteMeetingSettings;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers of the Site Meetings controllers. All permission checks are made here / in the actions (server side);
 * the Authorizable auto-derivation is switched off because Site Meetings has its own finer abilities.
 */
abstract class SiteMeetingBaseController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    protected function settings(?User $user = null): SiteMeetingSettings
    {
        return app(SiteMeetingSettings::class)->forAccount(optional($user ?? auth()->user())->account_id);
    }

    /** Abilities that change something — all of them are switched off for a user marked read-only. */
    private const WRITE_ABILITIES = ['create-site-meetings', 'update-site-meetings', 'delete-site-meetings', 'update-site-meeting-reports', 'finalise-site-meeting-reports', 'manage-site-meetings-settings'];

    /** The one place an ability is checked: a read-only user (not a Super Admin) is refused every write ability. */
    protected function allows(User $user, string $ability): bool
    {
        if (in_array($ability, self::WRITE_ABILITIES, true) && ! $user->hasRole('Super Admin') && $user->hasPermissionTo('view-only-site-meetings')) {
            return false;
        }

        return $user->can($ability);
    }

    /**
     * A meeting the user may not see answers 404, so its existence is not revealed. A write ability additionally needs the
     * meeting to belong to a site the user works on (seeing the whole schedule is wider than working on it).
     */
    protected function meetingFor(SiteMeeting $meeting, User $user, ?string $ability = null): SiteMeeting
    {
        abort_unless($meeting->account_id == $user->account_id && $meeting->isVisibleTo($user), 404);
        if ($ability) {
            abort_unless($this->allows($user, $ability), 403);
            if (in_array($ability, self::WRITE_ABILITIES, true)) {
                abort_unless($meeting->canWorkBy($user), 403, __('You can only work on meetings of the projects you are assigned to.'));
            }
        }

        return $meeting;
    }

    /** Sites (projects) the user may schedule meetings for: non-main warehouses they work with. */
    protected function sites(User $user, bool $forEntry = true)
    {
        $q = Warehouse::query()->ofAccount($user->account_id)->active()
            ->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses']);
        if ($forEntry) {
            $q->visible();
        }
        $ids = SiteMeeting::siteIdsFor($user);
        if ($ids !== null) {
            $q->whereIn('id', $ids ?: [0]);
        }

        return $q->orderBy('name')->get(['id', 'name', 'code', 'status'])
            ->map(fn ($w) => ['label' => $w->name.($w->code ? ' ('.$w->code.')' : ''), 'value' => $w->id, 'name' => $w->name, 'code' => $w->code])->values();
    }

    /** People who can be invited / chair / write the minutes: active users of the account. */
    protected function people(User $user)
    {
        return User::query()->where('account_id', $user->account_id)->where('is_active', true)->orderBy('name')
            ->with('roles:id,name')->get(['id', 'name', 'username'])
            ->map(fn ($u) => ['label' => $u->name, 'value' => $u->id, 'name' => $u->name, 'sub' => $u->roles->pluck('name')->first()])->values();
    }

    protected function abilities(User $user): array
    {
        return [
            'create' => $this->allows($user, 'create-site-meetings'),
            'update' => $this->allows($user, 'update-site-meetings'),
            'delete' => $this->allows($user, 'delete-site-meetings'),
            'report' => $this->allows($user, 'update-site-meeting-reports'),
            'finalise' => $this->allows($user, 'finalise-site-meeting-reports'),
            'print' => $user->can('print-site-meetings'),
            'history' => $user->can('view-site-meeting-history'),
            'settings' => $this->allows($user, 'manage-site-meetings-settings'),
            'readonly' => ! $user->hasRole('Super Admin') && $user->hasPermissionTo('view-only-site-meetings'),
            'super' => $user->hasRole('Super Admin'),
        ];
    }

    /** What the list / board / show pages need to draw one meeting. */
    protected function card(SiteMeeting $m, array $styles): array
    {
        return [
            'id' => $m->id,
            'reference' => $m->reference,
            'meeting_no' => $m->meeting_no,
            'title' => $m->display_title,
            'site' => ['id' => $m->warehouse_id, 'name' => optional($m->warehouse)->name, 'code' => optional($m->warehouse)->code],
            'date' => $m->meeting_date->toDateString(),
            'start' => SiteMeeting::hm($m->start_time),
            'end' => SiteMeeting::hm($m->end_time),
            'venue' => $m->venue,
            'status' => $m->status,
            'status_label' => $styles[$m->status]['label'] ?? $m->status,
            'status_color' => $styles[$m->status]['color'] ?? 'slate',
            'report_status' => $m->report_status,
            'report_label' => $styles[$m->report_status]['label'] ?? $m->report_status,
            'report_color' => $styles[$m->report_status]['color'] ?? 'slate',
            'chairperson' => $m->chairperson ? $m->chairperson->name : null,
            'recorder' => $m->recorder ? $m->recorder->name : null,
            'attendees_count' => (int) ($m->attendees_count ?? $m->attendees->count()),
            'attachments_count' => (int) ($m->attachments_count ?? 0),
            'can_work' => $this->canWork($m),
            'movable' => in_array($m->status, [SiteMeeting::SCHEDULED, SiteMeeting::POSTPONED], true) && ! $m->trashed() && $this->canWork($m) && $this->allows(auth()->user(), 'update-site-meetings'),
            'deleted_at' => $m->deleted_at,
        ];
    }

    /** The extra facts shown when a card on the weekly board is expanded: who is invited, the agenda and the info. */
    protected function more(SiteMeeting $m): array
    {
        return [
            'agenda' => \Illuminate\Support\Str::limit(trim((string) $m->agenda), 320),
            'info' => \Illuminate\Support\Str::limit(trim((string) $m->info), 320),
            'people' => $m->attendees->map(fn ($a) => ['name' => $a->name, 'designation' => $a->designation, 'organisation' => $a->organisation, 'attendance' => $a->attendance])->values()->all(),
        ];
    }

    /** May the logged-in person work on this meeting (their site, or one they prepare)? */
    protected function canWork(SiteMeeting $m): bool
    {
        return $m->canWorkBy(auth()->user());
    }

    protected function syncAttendees(SiteMeeting $meeting, array $list): void
    {
        $existing = $meeting->attendees()->get()->keyBy(fn ($a) => $a->user_id ? 'u'.$a->user_id : 'n'.mb_strtolower($a->name));
        $keep = [];
        foreach (array_values($list) as $i => $a) {
            $name = trim((string) ($a['name'] ?? ''));
            $userId = ! empty($a['user_id']) ? (int) $a['user_id'] : null;
            if ($userId) {
                $name = $name ?: (string) User::withTrashed()->where('id', $userId)->value('name');
            }
            if ($name === '') {
                continue;
            }
            $key = $userId ? 'u'.$userId : 'n'.mb_strtolower($name);
            $row = $existing[$key] ?? new SiteMeetingAttendee(['site_meeting_id' => $meeting->id]);
            $row->fill([
                'user_id' => $userId,
                'name' => mb_substr($name, 0, 190),
                'designation' => mb_substr(trim((string) ($a['designation'] ?? '')), 0, 190) ?: null,
                'organisation' => mb_substr(trim((string) ($a['organisation'] ?? '')), 0, 190) ?: null,
                'attendance' => in_array($a['attendance'] ?? null, SiteMeetingAttendee::ATTENDANCE, true) ? $a['attendance'] : ($row->attendance ?: 'Invited'),
                'sort' => $i,
            ]);
            $row->site_meeting_id = $meeting->id;
            $row->save();
            $keep[] = $row->id;
        }
        $meeting->attendees()->whereNotIn('id', $keep ?: [0])->delete();
    }

    protected function attendeePayload(SiteMeeting $meeting): array
    {
        return $meeting->attendees->map(fn ($a) => [
            'id' => $a->id, 'user_id' => $a->user_id, 'name' => $a->name, 'designation' => $a->designation,
            'organisation' => $a->organisation, 'attendance' => $a->attendance,
        ])->values()->all();
    }

    protected function attachmentPayload(SiteMeeting $meeting): array
    {
        return $meeting->attachments->map(fn ($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'filesize' => $a->filesize,
            'is_image' => str_starts_with(strtolower($a->filetype ?? ''), 'image/'),
            'is_pdf' => strtolower($a->filetype ?? '') === 'application/pdf',
            'preview_url' => route('site-meetings.attachments.download', [$meeting->id, $a->id]).'?disposition=inline',
            'download_url' => route('site-meetings.attachments.download', [$meeting->id, $a->id]),
        ])->values()->all();
    }
}
