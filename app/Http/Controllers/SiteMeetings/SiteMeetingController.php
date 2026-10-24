<?php

namespace App\Http\Controllers\SiteMeetings;

use App\Models\SiteMeeting;
use App\Models\User;
use App\Services\SiteMeetings\SiteMeetingLogger;
use App\Services\SiteMeetings\SiteMeetingNotifier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class SiteMeetingController extends SiteMeetingBaseController
{
    private const FILTER_KEYS = ['search', 'warehouse_id', 'status', 'report_status', 'date_from', 'date_to', 'view'];

    // ---- the weekly schedule board ------------------------------------------------------------------------------------

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('read-site-meetings'), 403);

        $settings = $this->settings($user);
        $styles = $settings->styles();
        $weekStart = (int) $settings->get('week_start');
        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('week')) ? $request->query('week') : now()->toDateString();
        [$start, $end] = SiteMeeting::weekOf($anchor, $weekStart);

        $filters = [
            'warehouse_id' => $request->query('warehouse_id') ?: null,
            'status' => in_array($request->query('status'), SiteMeetingSettingsKeys::status(), true) ? $request->query('status') : null,
            'view' => in_array($request->query('view'), ['reports_due', 'moved'], true) ? $request->query('view') : null,
        ];

        $week = SiteMeeting::query()->visibleTo($user)->whereBetween('meeting_date', [$start->toDateString(), $end->toDateString()])
            ->when($filters['warehouse_id'], fn ($q, $v) => $q->where('site_meetings.warehouse_id', $v));

        // the cards above the board count the week as filtered by site only, and light up when applied
        $counts = (clone $week)->toBase()->selectRaw("COUNT(*) as total, COALESCE(SUM(status = 'Scheduled'), 0) as scheduled, COALESCE(SUM(status = 'Held'), 0) as held, COALESCE(SUM(status IN ('Postponed','Cancelled')), 0) as moved, COALESCE(SUM(meeting_date <= ? AND status IN ('Scheduled','Held') AND report_status <> 'Finalised'), 0) as reports_due", [now()->toDateString()])->first();

        $meetings = (clone $week)
            ->when($filters['status'], fn ($q, $v) => $q->where('site_meetings.status', $v))
            ->when($filters['view'] === 'reports_due', fn ($q) => $q->where('meeting_date', '<=', now()->toDateString())->whereIn('status', ['Scheduled', 'Held'])->where('report_status', '<>', 'Finalised'))
            ->when($filters['view'] === 'moved', fn ($q) => $q->whereIn('status', ['Postponed', 'Cancelled']))
            ->with(['warehouse:id,name,code', 'chairperson:id,name', 'recorder:id,name', 'attendees:id,site_meeting_id,name,designation,organisation,attendance'])->withCount(['attendees', 'attachments'])
            ->orderBy('meeting_date')->orderByRaw('start_time IS NULL')->orderBy('start_time')->get();

        $byDate = $meetings->groupBy(fn ($m) => $m->meeting_date->toDateString());
        $shown = $settings->weekdays();
        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            if (! in_array($d->dayOfWeek, $shown, true) && ! isset($byDate[$key])) {
                continue;
            }
            $days[] = [
                'date' => $key,
                'short' => $d->format('D'),
                'name' => $d->format('l'),
                'day' => $d->format('d'),
                'month' => $d->format('M'),
                'is_today' => $d->isToday(),
                'is_past' => $d->lt(now()->startOfDay()),
                'meetings' => ($byDate[$key] ?? collect())->map(fn ($m) => $this->card($m, $styles) + ['more' => $this->more($m)])->values(),
            ];
        }

        return Inertia::render('SiteMeetings/Schedule', [
            'week' => [
                'start' => $start->toDateString(), 'end' => $end->toDateString(),
                'label' => $start->format('d M').' – '.$end->format('d M Y'),
                'prev' => $start->copy()->subWeek()->toDateString(), 'next' => $start->copy()->addWeek()->toDateString(),
                'is_current' => now()->between($start, $end->copy()->endOfDay()),
                'number' => (int) $start->isoWeek(),
            ],
            'days' => $days,
            'stats' => ['total' => (int) $counts->total, 'Scheduled' => (int) $counts->scheduled, 'Held' => (int) $counts->held, 'moved' => (int) $counts->moved, 'reports_due' => (int) $counts->reports_due],
            'filters' => $filters,
            'sites' => $this->sites($user, false),
            'statuses' => $styles,
            'can' => $this->abilities($user),
        ]);
    }

    // ---- the list -----------------------------------------------------------------------------------------------------

    /** Small JSON summary of this week's meetings, for the icon on the dashboard. */
    public function weekSummary(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('read-site-meetings'), 403);

        $weekStart = (int) $this->settings($user)->get('week_start');
        [$start, $end] = SiteMeeting::weekOf(now()->toDateString(), $weekStart);
        $meetings = SiteMeeting::query()->visibleTo($user)
            ->whereBetween('meeting_date', [$start->toDateString(), $end->toDateString()])->where('status', '<>', 'Cancelled')
            ->with('warehouse:id,name')->orderBy('meeting_date')->orderByRaw('start_time IS NULL')->orderBy('start_time')->get();

        return response()->json([
            'week' => $start->copy()->addDays(3)->isoWeek(),
            'range' => $start->format('d M').' – '.$end->format('d M Y'),
            'count' => $meetings->count(),
            'meetings' => $meetings->take(7)->map(fn (SiteMeeting $m) => [
                'id' => $m->id, 'day' => $m->meeting_date->format('D'), 'date' => $m->meeting_date->format('d M'), 'today' => $m->meeting_date->isToday(),
                'project' => optional($m->warehouse)->name ?: $m->display_title, 'time' => SiteMeeting::hm($m->start_time), 'status' => $m->status,
            ])->values(),
        ]);
    }

    public function list(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('read-site-meetings'), 403);

        $styles = $this->settings($user)->styles();
        $filters = array_merge(array_fill_keys(self::FILTER_KEYS, null), array_filter($request->only(self::FILTER_KEYS), fn ($v) => $v !== null && $v !== ''));
        $trashed = $request->query('trashed') === 'only' && $this->allows($user, 'delete-site-meetings');

        $query = SiteMeeting::query()->visibleTo($user)->filter($filters)
            ->when(($filters['view'] ?? null) === 'reports_due', fn ($q) => $q->where('meeting_date', '<=', now()->toDateString())->whereIn('status', ['Scheduled', 'Held'])->where('report_status', '<>', 'Finalised'))
            ->when(($filters['view'] ?? null) === 'moved', fn ($q) => $q->whereIn('status', ['Postponed', 'Cancelled']))
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->with(['warehouse:id,name,code', 'chairperson:id,name', 'recorder:id,name'])->withCount(['attendees', 'attachments'])
            ->orderByDesc('meeting_date')->orderByDesc('id');

        $all = SiteMeeting::query()->visibleTo($user)->toBase()->selectRaw("COUNT(*) as total, COALESCE(SUM(status = 'Scheduled'), 0) as scheduled, COALESCE(SUM(status = 'Held'), 0) as held, COALESCE(SUM(status IN ('Postponed','Cancelled')), 0) as moved, COALESCE(SUM(meeting_date <= ? AND status IN ('Scheduled','Held') AND report_status <> 'Finalised'), 0) as reports_due", [now()->toDateString()])->first();

        $page = $query->paginate(25)->withQueryString();
        $page->getCollection()->transform(fn ($m) => $this->card($m, $styles));

        return Inertia::render('SiteMeetings/Index', [
            'meetings' => paginated_response($page),
            'filters' => array_merge($filters, ['trashed' => $trashed ? 'only' : null]),
            'stats' => ['total' => (int) $all->total, 'Scheduled' => (int) $all->scheduled, 'Held' => (int) $all->held, 'moved' => (int) $all->moved, 'reports_due' => (int) $all->reports_due],
            'sites' => $this->sites($user, false),
            'statuses' => $styles,
            'can' => $this->abilities($user),
        ]);
    }

    // ---- create / edit ------------------------------------------------------------------------------------------------

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($this->allows($user, 'create-site-meetings'), 403);
        $s = $this->settings($user);

        // "Duplicate": start from an existing meeting — same site, time, venue, people and agenda, one week later
        $copy = null;
        $from = $request->filled('from') ? SiteMeeting::query()->find((int) $request->query('from')) : null;
        if ($from && $from->account_id == $user->account_id && $from->isVisibleTo($user) && $from->canWorkBy($user)) {
            $from->load(['attendees', 'warehouse:id,name']);
            $next = $from->meeting_date->copy()->addWeek();
            while ($next->lt(now()->startOfDay())) {
                $next->addWeek(); // never schedule the copy in the past: next same weekday from today
            }
            $copy = [
                'id' => $from->id, 'reference' => $from->reference, 'title' => $from->display_title, 'site' => optional($from->warehouse)->name,
                'date' => $next->toDateString(), 'warehouse_id' => $from->warehouse_id, 'start_time' => SiteMeeting::hm($from->start_time), 'end_time' => SiteMeeting::hm($from->end_time),
                'venue' => $from->venue, 'chairperson_id' => $from->chairperson_id, 'recorder_id' => $from->recorder_id, 'prepared_by_id' => $from->prepared_by ?: $from->created_by,
                'agenda' => $from->agenda, 'info' => $from->info, 'remarks' => str_starts_with((string) $from->remarks, 'SAMPLE') ? null : $from->remarks,
                'attendees' => $this->attendeePayload($from),
                'has_minutes' => $from->report_status !== SiteMeeting::REPORT_NONE && $from->rows()->exists(),
            ];
        }

        return Inertia::render('SiteMeetings/Form', [
            'meeting' => null,
            'copy' => $copy,
            'me' => ['id' => $user->id, 'name' => $user->name],
            'defaults' => [
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? $request->query('date') : now()->toDateString(),
                'warehouse_id' => $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null,
                'start_time' => $s->get('default_start_time'),
                'end_time' => $this->addMinutes((string) $s->get('default_start_time'), (int) $s->get('default_duration_min')),
                'venue' => $s->get('default_venue'),
                'title_prefix' => $s->get('title_prefix'),
                'repeat_max' => (int) $s->get('repeat_max_weeks'),
            ],
            'sites' => $this->sites($user),
            'people' => $this->people($user),
            'can' => $this->abilities($user),
        ]);
    }

    /** Copies the layout snapshot and all rows of the source minutes into a new meeting; its minutes start as a Draft. */
    private function copyMinutes(SiteMeeting $source, SiteMeeting $target, User $user): void
    {
        if (! $source->rows()->exists()) {
            return;
        }
        DB::transaction(function () use ($source, $target, $user) {
            foreach ($source->rows()->get() as $row) {
                \App\Models\SiteMeetingRow::create(['site_meeting_id' => $target->id, 'section_key' => $row->section_key, 'sort' => $row->sort, 'data' => $row->data]);
            }
            $target->forceFill([
                'template' => $source->template, 'report_status' => SiteMeeting::REPORT_DRAFT,
                'report_started_at' => now(), 'report_started_by' => $user->id,
            ])->save();
        });
        SiteMeetingLogger::log($user, $target, 'REPORT_START', null, ['copied_from' => $source->reference]);
    }

    /**
     * "Is something already booked there?" — the meetings of the site on that day (any status but cancelled), for the warning shown
     * on the schedule form. It only warns; two meetings on one day are allowed.
     */
    public function conflicts(Request $request)
    {
        $user = $request->user();
        abort_unless($this->allows($user, 'create-site-meetings') || $this->allows($user, 'update-site-meetings'), 403);
        $data = $request->validate(['warehouse_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d'], 'except' => ['nullable', 'integer']]);

        return SiteMeeting::query()->visibleTo($user)->where('warehouse_id', $data['warehouse_id'])->where('meeting_date', $data['date'])
            ->where('status', '!=', SiteMeeting::CANCELLED)->when($data['except'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->orderBy('start_time')->get(['id', 'reference', 'meeting_no', 'title', 'start_time', 'end_time', 'status'])
            ->map(fn ($m) => ['id' => $m->id, 'reference' => $m->reference, 'title' => $m->display_title, 'start' => SiteMeeting::hm($m->start_time), 'end' => SiteMeeting::hm($m->end_time), 'status' => $m->status])->values();
    }

    public function nextNumber(Request $request)
    {
        abort_unless($this->allows($request->user(), 'create-site-meetings') || $this->allows($request->user(), 'update-site-meetings'), 403);
        $id = (int) $request->query('warehouse_id');
        abort_unless($id && $this->sites($request->user(), false)->contains('value', $id), 403);

        return ['next' => SiteMeeting::nextMeetingNo($id)];
    }

    private function rules(Request $request, bool $creating): array
    {
        $max = (int) $this->settings($request->user())->get('repeat_max_weeks');

        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'meeting_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'venue' => ['nullable', 'string', 'max:190'],
            'title' => ['nullable', 'string', 'max:190'],
            'meeting_no' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'chairperson_id' => ['nullable', 'integer', 'exists:users,id'],
            'recorder_id' => ['nullable', 'integer', 'exists:users,id'],
            'prepared_by' => ['nullable', 'integer', 'exists:users,id'],
            'agenda' => ['nullable', 'string', 'max:10000'],
            'info' => ['nullable', 'string', 'max:10000'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'attendees' => ['nullable', 'array', 'max:80'],
            'attendees.*.name' => ['nullable', 'string', 'max:190'],
            'attendees.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'attendees.*.designation' => ['nullable', 'string', 'max:190'],
            'attendees.*.organisation' => ['nullable', 'string', 'max:190'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:15360', 'mimes:'.attachment_exts('jpg,png,pdf,docx,xlsx,zip')],
            'copy_from' => ['nullable', 'integer'],
            'copy_minutes' => ['nullable', 'boolean'],
            'repeat_weeks' => $creating ? ['nullable', 'integer', 'min:0', 'max:'.max(0, $max)] : ['nullable'],
        ];
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->allows($user, 'create-site-meetings'), 403);
        $data = $request->validate($this->rules($request, true));
        $this->guardSite($user, (int) $data['warehouse_id']);

        $settings = $this->settings($user);
        $template = array_values(array_filter($settings->template(), fn ($s) => $s['enabled']));
        $repeat = (int) ($data['repeat_weeks'] ?? 0);
        $series = $repeat > 0 ? (string) Str::uuid() : null;

        // The person preparing the meeting and the one writing the minutes are the logged-in user; only a Super Admin may name someone else.
        $isSuper = $user->hasRole('Super Admin');
        $preparedBy = $isSuper && ! empty($data['prepared_by']) ? (int) $data['prepared_by'] : $user->id;
        $recorderId = $isSuper ? (! empty($data['recorder_id']) ? (int) $data['recorder_id'] : $user->id) : $user->id;

        $created = DB::transaction(function () use ($data, $user, $template, $repeat, $series, $request, $preparedBy, $recorderId) {
            $out = [];
            $no = ! empty($data['meeting_no']) ? (int) $data['meeting_no'] : SiteMeeting::nextMeetingNo((int) $data['warehouse_id']);
            for ($i = 0; $i <= $repeat; $i++) {
                $meeting = SiteMeeting::create([
                    'account_id' => $user->account_id,
                    'reference' => \App\Support\ReferenceLock::run('site_meetings', fn () => SiteMeeting::nextReference()),
                    'meeting_no' => $no + $i,
                    'warehouse_id' => $data['warehouse_id'],
                    'title' => $data['title'] ?? null,
                    'meeting_date' => Carbon::parse($data['meeting_date'])->addWeeks($i)->toDateString(),
                    'start_time' => $data['start_time'] ?? null,
                    'end_time' => $data['end_time'] ?? null,
                    'venue' => $data['venue'] ?? null,
                    'chairperson_id' => $data['chairperson_id'] ?? null,
                    'recorder_id' => $recorderId,
                    'prepared_by' => $preparedBy,
                    'agenda' => $data['agenda'] ?? null,
                    'info' => $data['info'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'status' => SiteMeeting::SCHEDULED,
                    'report_status' => SiteMeeting::REPORT_NONE,
                    'template' => $template,
                    'series_uuid' => $series,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
                $this->syncAttendees($meeting, $data['attendees'] ?? []);
                if ($request->file('attachments')) {
                    $meeting->saveAttachments($request->file('attachments'));
                }
                $out[] = $meeting;
            }

            return $out;
        });

        // A duplicate may carry the source minutes over (layout + every row) so the engineer edits instead of retyping.
        $source = ! empty($data['copy_from']) ? SiteMeeting::query()->find((int) $data['copy_from']) : null;
        if ($source && ($source->account_id != $user->account_id || ! $source->isVisibleTo($user) || ! $source->canWorkBy($user))) {
            $source = null;
        }
        if ($source && ! empty($data['copy_minutes']) && $this->allows($user, 'update-site-meeting-reports')) {
            $this->copyMinutes($source, $created[0], $user);
        }

        foreach ($created as $meeting) {
            SiteMeetingLogger::log($user, $meeting, 'MEETING_CREATE', null, ['series' => $series, 'date' => $meeting->meeting_date->toDateString()] + ($source ? ['copied_from' => $source->reference, 'minutes_copied' => ! empty($data['copy_minutes']) && $meeting->is($created[0])] : []));
        }
        $first = $created[0];
        SiteMeetingNotifier::scheduled($first, null, $user);

        return redirect()->route('site-meetings.show', $first->id)
            ->with('message', count($created) > 1 ? __(':n weekly meetings scheduled.', ['n' => count($created)]) : __('Meeting scheduled.'));
    }

    public function edit(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'update-site-meetings');
        $meeting->load(['attendees', 'attachments']);
        $s = $this->settings($user);

        return Inertia::render('SiteMeetings/Form', [
            'meeting' => $this->detail($meeting, $s->styles()) + [
                'attendees' => $this->attendeePayload($meeting),
                'attachments' => $this->attachmentPayload($meeting),
                'chairperson_id' => $meeting->chairperson_id, 'recorder_id' => $meeting->recorder_id, 'prepared_by_id' => $meeting->prepared_by ?: $meeting->created_by,
                'warehouse_id' => $meeting->warehouse_id, 'raw_title' => $meeting->title,
            ],
            'me' => ['id' => $user->id, 'name' => $user->name],
            'defaults' => ['title_prefix' => $s->get('title_prefix'), 'repeat_max' => 0],
            'sites' => $this->sites($user),
            'people' => $this->people($user),
            'can' => $this->abilities($user),
        ]);
    }

    public function update(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'update-site-meetings');
        abort_if($meeting->trashed(), 404);
        $data = $request->validate($this->rules($request, false));
        $this->guardSite($user, (int) $data['warehouse_id']);

        $before = $meeting->only(['warehouse_id', 'meeting_no', 'title', 'meeting_date', 'start_time', 'end_time', 'venue', 'chairperson_id', 'recorder_id', 'agenda', 'info', 'remarks']);
        $before['meeting_date'] = $meeting->meeting_date->toDateString();
        $before['start_time'] = SiteMeeting::hm($before['start_time']);
        $before['end_time'] = SiteMeeting::hm($before['end_time']);

        $newInvited = [];
        $isSuper = $user->hasRole('Super Admin');
        DB::transaction(function () use ($meeting, $data, $user, $request, &$newInvited, $isSuper) {
            $had = $meeting->attendees()->whereNotNull('user_id')->pluck('user_id')->all();
            $meeting->update([
                'warehouse_id' => $data['warehouse_id'],
                'meeting_no' => ! empty($data['meeting_no']) ? (int) $data['meeting_no'] : $meeting->meeting_no,
                'title' => $data['title'] ?? null,
                'meeting_date' => $data['meeting_date'],
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'venue' => $data['venue'] ?? null,
                'chairperson_id' => $data['chairperson_id'] ?? null,
                // preparer / minutes writer are fixed for everybody except a Super Admin, who may reassign them
                'recorder_id' => $isSuper ? ($data['recorder_id'] ?? $meeting->recorder_id) : ($meeting->recorder_id ?: $user->id),
                'prepared_by' => $isSuper && ! empty($data['prepared_by']) ? (int) $data['prepared_by'] : ($meeting->prepared_by ?: $meeting->created_by),
                'agenda' => $data['agenda'] ?? null,
                'info' => $data['info'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'updated_by' => $user->id,
            ]);
            $this->syncAttendees($meeting, $data['attendees'] ?? []);
            $newInvited = array_values(array_diff($meeting->attendees()->whereNotNull('user_id')->pluck('user_id')->all(), $had));
            if ($request->file('attachments')) {
                $meeting->saveAttachments($request->file('attachments'));
            }
        });

        $meeting->refresh();
        $after = $meeting->only(array_keys($before));
        $after['meeting_date'] = $meeting->meeting_date->toDateString();
        $after['start_time'] = SiteMeeting::hm($after['start_time']);
        $after['end_time'] = SiteMeeting::hm($after['end_time']);
        $labels = ['warehouse_id' => 'site', 'meeting_no' => 'meeting number', 'title' => 'title', 'meeting_date' => 'date', 'start_time' => 'start time', 'end_time' => 'end time', 'venue' => 'venue', 'chairperson_id' => 'chairperson', 'recorder_id' => 'minutes writer', 'agenda' => 'agenda', 'info' => 'info', 'remarks' => 'remarks'];
        $changes = [];
        foreach ($labels as $key => $label) {
            if ((string) ($before[$key] ?? '') !== (string) ($after[$key] ?? '')) {
                $changes[$label] = ['from' => $before[$key] ?? null, 'to' => $after[$key] ?? null];
            }
        }
        SiteMeetingLogger::log($user, $meeting, 'MEETING_UPDATE', null, ['changes' => $changes, 'changed' => array_keys($changes)]);
        if ($request->file('attachments')) {
            $names = [];
            foreach ($request->file('attachments') as $file) {
                SiteMeetingLogger::log($user, $meeting, 'ATTACHMENT_ADD', $file->getClientOriginalName());
                $names[] = $file->getClientOriginalName();
            }
            SiteMeetingNotifier::attachment($meeting, 'attachment_added', implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ' +'.(count($names) - 3) : ''), $user);
        }
        if ($newInvited) {
            SiteMeetingNotifier::scheduled($meeting, $newInvited, $user);
        }
        if (array_intersect(array_keys($changes), ['site', 'date', 'start time', 'end time', 'venue'])) {
            SiteMeetingNotifier::changed($meeting, 'was updated', $user);
        }

        return redirect()->route('site-meetings.show', $meeting->id)->with('message', __('Meeting updated.'));
    }

    public function destroy(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'delete-site-meetings');
        $meeting->forceFill(['deleted_by' => $user->id])->saveQuietly();
        $meeting->delete();
        SiteMeetingLogger::log($user, $meeting, 'MEETING_DELETE');
        SiteMeetingNotifier::changed($meeting, 'was deleted', $user, 'deleted');

        return redirect()->route('site-meetings.index')->with('message', __('Meeting deleted.'));
    }

    public function restore(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        abort_unless($this->allows($user, 'delete-site-meetings') && $site_meeting->account_id == $user->account_id && $site_meeting->canWorkBy($user), 403);
        $site_meeting->restore();
        $site_meeting->forceFill(['deleted_by' => null])->saveQuietly();
        SiteMeetingLogger::log($user, $site_meeting, 'MEETING_RESTORE');
        SiteMeetingNotifier::restored($site_meeting, $user);

        return back()->with('message', __('Meeting restored.'));
    }

    // ---- one meeting --------------------------------------------------------------------------------------------------

    public function show(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user);
        $meeting->load(['warehouse:id,name,code', 'chairperson:id,name', 'recorder:id,name', 'preparer:id,name', 'creator:id,name', 'updater:id,name', 'attendees', 'attachments']);
        $s = $this->settings($user);
        $can = $this->abilities($user);

        SiteMeetingLogger::logView($user, $meeting, 'MEETING_VIEW', (int) $s->get('history_view_minutes'));

        // how much of the minutes is filled in, per section, for the summary on this page
        $rows = $meeting->rows()->get()->groupBy('section_key');
        $sections = collect($meeting->template ?? [])->map(fn ($sec) => [
            'key' => $sec['key'], 'title' => $sec['title'], 'icon' => $sec['icon'] ?? 'doc-text', 'color' => $sec['color'] ?? 'sky', 'type' => $sec['type'],
            'filled' => ($rows[$sec['key']] ?? collect())->filter(fn ($r) => collect($r->data)->except('_group')->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty())->count(),
        ])->values();

        return Inertia::render('SiteMeetings/Show', [
            'meeting' => $this->detail($meeting, $s->styles()) + [
                'attendees' => $this->attendeePayload($meeting),
                'attachments' => $this->attachmentPayload($meeting),
            ],
            'sections' => $sections,
            'history' => $can['history'] ? SiteMeetingLogger::timeline($meeting) : [],
            'viewers' => $can['history'] ? SiteMeetingLogger::viewers($meeting) : [],
            'statuses' => $s->styles(),
            'can' => $can + ['edit_this' => $can['update'] && ! $meeting->trashed() && $meeting->canWorkBy($user), 'report_this' => $can['report'] && ! $meeting->reportLocked() && $meeting->canWorkBy($user), 'work' => $meeting->canWorkBy($user)],
        ]);
    }

    private function detail(SiteMeeting $m, array $styles): array
    {
        return $this->card($m, $styles) + [
            'agenda' => $m->agenda, 'info' => $m->info, 'remarks' => $m->remarks,
            'status_reason' => $m->status_reason, 'postponed_to' => optional($m->postponed_to)->toDateString(),
            'prepared_by' => optional($m->preparer)->name ?: optional($m->creator)->name,
            'created_by' => optional($m->creator)->name, 'created_at' => optional($m->created_at)->toIso8601String(),
            'updated_by' => optional($m->updater)->name, 'updated_at' => optional($m->updated_at)->toIso8601String(),
            'report_started_at' => optional($m->report_started_at)->toIso8601String(),
            'report_submitted_at' => optional($m->report_submitted_at)->toIso8601String(),
            'report_finalised_at' => optional($m->report_finalised_at)->toIso8601String(),
            'series' => $m->series_uuid ? SiteMeeting::where('series_uuid', $m->series_uuid)->count() : 0,
        ];
    }

    public function status(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'update-site-meetings');
        abort_if($meeting->trashed(), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(SiteMeetingSettingsKeys::status())],
            'reason' => ['nullable', 'string', 'max:1000'],
            'postponed_to' => ['nullable', 'date'],
        ]);
        abort_if($data['status'] === $meeting->status && $data['status'] !== SiteMeeting::POSTPONED, 422, __('The meeting already has that status.'));
        if (in_array($data['status'], [SiteMeeting::POSTPONED, SiteMeeting::CANCELLED], true) && trim((string) ($data['reason'] ?? '')) === '') {
            return back()->withErrors(['reason' => __('Please give a reason.')]);
        }

        $from = $meeting->status;
        $meeting->forceFill([
            'status' => $data['status'],
            'status_reason' => in_array($data['status'], [SiteMeeting::POSTPONED, SiteMeeting::CANCELLED], true) ? trim($data['reason']) : null,
            'postponed_to' => $data['status'] === SiteMeeting::POSTPONED ? ($data['postponed_to'] ?? null) : null,
            'updated_by' => $user->id,
        ])->save();

        SiteMeetingLogger::log($user, $meeting, 'MEETING_STATUS', null, ['from' => $from, 'to' => $data['status'], 'reason' => $data['reason'] ?? null]);
        SiteMeetingNotifier::changed($meeting, 'is now '.strtolower($data['status']), $user, 'status');

        return back()->with('message', __('Meeting marked :status.', ['status' => strtolower($data['status'])]));
    }

    /**
     * Drag and drop on the weekly board: the meeting goes to another day of the same week and its date is changed for
     * real (same time, venue, people). Only meetings still to be held can be moved; a postponed one becomes Scheduled again.
     */
    public function move(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'update-site-meetings');
        abort_if($meeting->trashed(), 404);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        abort_unless(in_array($meeting->status, [SiteMeeting::SCHEDULED, SiteMeeting::POSTPONED], true), 422, __('Only a scheduled or postponed meeting can be moved.'));

        $weekStart = (int) $this->settings($user)->get('week_start');
        [$from] = SiteMeeting::weekOf($meeting->meeting_date, $weekStart);
        [$to] = SiteMeeting::weekOf($data['date'], $weekStart);
        abort_unless($from->equalTo($to), 422, __('A meeting can only be dragged to another day of the same week. Use Edit to choose a different week.'));

        $old = $meeting->meeting_date->toDateString();
        if ($old === $data['date']) {
            return back();
        }
        $wasPostponed = $meeting->status === SiteMeeting::POSTPONED;
        $meeting->forceFill([
            'meeting_date' => $data['date'],
            'status' => SiteMeeting::SCHEDULED,
            'status_reason' => $wasPostponed ? null : $meeting->status_reason,
            'postponed_to' => $wasPostponed ? null : $meeting->postponed_to,
            'updated_by' => $user->id,
        ])->save();

        SiteMeetingLogger::log($user, $meeting, 'MEETING_MOVE', null, ['changes' => ['date' => ['from' => $old, 'to' => $data['date']]], 'changed' => ['date'], 'dragged' => true]);
        SiteMeetingNotifier::changed($meeting, 'moved to '.Carbon::parse($data['date'])->format('D, d M'), $user);

        return back()->with('message', __('Meeting moved to :date.', ['date' => Carbon::parse($data['date'])->format('l, d M Y')]));
    }

    // ---- attachments --------------------------------------------------------------------------------------------------

    public function downloadAttachment(Request $request, SiteMeeting $site_meeting, Attachment $attachment)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user);
        abort_unless((int) $attachment->model_id === (int) $meeting->id && $attachment->model_type === SiteMeeting::class, 404);

        if ($request->query('disposition') === 'inline') {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $attachment->title);
        }
        SiteMeetingLogger::log($user, $meeting, 'ATTACHMENT_DOWNLOAD', $attachment->title);

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title);
    }

    public function destroyAttachment(Request $request, SiteMeeting $site_meeting, Attachment $attachment)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'update-site-meetings');
        abort_unless((int) $attachment->model_id === (int) $meeting->id && $attachment->model_type === SiteMeeting::class, 404);

        $title = $attachment->title;
        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();
        SiteMeetingLogger::log($user, $meeting, 'ATTACHMENT_REMOVE', $title);
        SiteMeetingNotifier::attachment($meeting, 'attachment_removed', (string) $title, $user);

        return back()->with('message', __('Attachment removed.'));
    }

    // ---- print / download ---------------------------------------------------------------------------------------------

    public function print(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'print-site-meetings');
        SiteMeetingLogger::log($user, $meeting, 'MEETING_PRINT');

        return view('site-meetings.meeting-print', $this->sheet($meeting, false));
    }

    public function pdf(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'print-site-meetings');
        SiteMeetingLogger::log($user, $meeting, 'MEETING_DOWNLOAD');

        return Pdf::loadView('site-meetings.meeting-print', $this->sheet($meeting, true))->setPaper('a4')->download('site-meeting-'.$meeting->reference.'.pdf');
    }

    private function sheet(SiteMeeting $meeting, bool $isPdf): array
    {
        $user = auth()->user();
        $meeting->load(['warehouse', 'chairperson:id,name', 'recorder:id,name', 'creator:id,name', 'attendees', 'attachments']);

        return [
            'meeting' => $meeting, 'isPdf' => $isPdf, 'companyName' => optional($user->account)->name, 'printedBy' => $user->name, 'printedAt' => now(),
            'styles' => $this->settings($user)->styles(), 'sign' => ['left' => $this->settings($user)->get('sign_left'), 'right' => $this->settings($user)->get('sign_right')],
            'qr' => \App\Support\PrintQrCode::dataUri(route('site-meetings.show', $meeting->id)),
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('site_meeting_sheet', $meeting),
        ];
    }

    public function printWeek(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('print-site-meetings') && $user->can('read-site-meetings'), 403);
        SiteMeetingLogger::log($user, null, 'WEEK_PRINT', null, ['week' => $request->query('week')]);

        return view('site-meetings.week-print', $this->week($request, false));
    }

    public function pdfWeek(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('print-site-meetings') && $user->can('read-site-meetings'), 403);
        SiteMeetingLogger::log($user, null, 'WEEK_DOWNLOAD', null, ['week' => $request->query('week')]);
        $data = $this->week($request, true);

        return Pdf::loadView('site-meetings.week-print', $data)->setPaper('a4', 'landscape')->download('site-meeting-schedule-'.$data['start']->format('Y-m-d').'.pdf');
    }

    private function week(Request $request, bool $isPdf): array
    {
        $user = $request->user();
        $s = $this->settings($user);
        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('week')) ? $request->query('week') : now()->toDateString();
        [$start, $end] = SiteMeeting::weekOf($anchor, (int) $s->get('week_start'));
        $siteId = $request->query('warehouse_id') ?: null;

        $meetings = SiteMeeting::query()->visibleTo($user)->whereBetween('meeting_date', [$start->toDateString(), $end->toDateString()])
            ->when($siteId, fn ($q, $v) => $q->where('site_meetings.warehouse_id', $v))
            ->with(['warehouse:id,name,code', 'chairperson:id,name', 'recorder:id,name'])->withCount('attendees')
            ->orderBy('meeting_date')->orderByRaw('start_time IS NULL')->orderBy('start_time')->get();

        return [
            'start' => $start, 'end' => $end, 'meetings' => $meetings->groupBy(fn ($m) => $m->meeting_date->toDateString()), 'total' => $meetings->count(),
            'site' => $siteId ? optional(\App\Models\Warehouse::find($siteId))->name : null,
            'isPdf' => $isPdf, 'companyName' => optional($user->account)->name, 'styles' => $s->styles(),
            'printedBy' => $user->name, 'printedAt' => now(), 'shown' => $s->weekdays(),
        ];
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    private function guardSite(User $user, int $warehouseId): void
    {
        abort_unless($this->sites($user)->contains('value', $warehouseId), 403, __('You cannot schedule meetings for that site.'));
    }

    private function addMinutes(string $hm, int $minutes): ?string
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $hm)) {
            return null;
        }

        return Carbon::createFromFormat('H:i', $hm)->addMinutes(max(0, $minutes))->format('H:i');
    }
}
