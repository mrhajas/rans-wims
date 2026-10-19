<?php

namespace App\Http\Controllers\DigitalAttendance;

use App\Http\Controllers\Controller;
use App\Http\Resources\DigitalAttendanceRecordCollection;
use App\Models\DigitalAttendanceEvent;
use App\Models\DigitalAttendanceRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DigitalAttendanceAdminController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function dashboard(Request $request)
    {
        $this->authorize('read-all-digital-attendance');

        $today = now()->toDateString();

        $totalStaff = User::query()->where('is_active', true)->count();

        $todayRecords = DigitalAttendanceRecord::query()->active()->whereDate('attendance_date', $today)->get();
        $clockedIn = $todayRecords->where('status', DigitalAttendanceRecord::STATUS_CLOCKED_IN)->count();
        $clockedOut = $todayRecords->where('status', DigitalAttendanceRecord::STATUS_CLOCKED_OUT)->count();
        $late = $todayRecords->where('is_late', true)->count();
        $missing = DigitalAttendanceRecord::query()->where('status', DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT)->whereDate('attendance_date', '>=', now()->subDays(2))->count();
        $totalMinutesToday = $todayRecords->sum(fn (DigitalAttendanceRecord $r) => $r->worked_minutes_live);

        $trend = DigitalAttendanceRecord::query()
            ->active()
            ->whereBetween('attendance_date', [now()->subDays(13)->toDateString(), $today])
            ->select('attendance_date', DB::raw('count(*) as days_count'), DB::raw('sum(coalesce(worked_minutes,0)) as minutes_sum'))
            ->groupBy('attendance_date')
            ->orderBy('attendance_date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->attendance_date instanceof \Carbon\Carbon ? $row->attendance_date->toDateString() : $row->attendance_date,
                'count' => (int) $row->days_count,
                'hours' => round($row->minutes_sum / 60, 1),
            ]);

        $statusDistribution = DigitalAttendanceRecord::query()
            ->whereDate('attendance_date', '>=', now()->subDays(29)->toDateString())
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('DigitalAttendance/Admin/Dashboard', [
            'stats' => [
                'total_staff' => $totalStaff,
                'clocked_in' => $clockedIn,
                'clocked_out' => $clockedOut,
                'late' => $late,
                'missing_clock_out' => $missing,
                'total_hours_today_label' => sprintf('%dh %02dm', intdiv($totalMinutesToday, 60), $totalMinutesToday % 60),
            ],
            'trend' => $trend,
            'statusDistribution' => $statusDistribution,
        ]);
    }

    public function records(Request $request)
    {
        $this->authorize('read-all-digital-attendance');

        $filters = [
            'user_id' => $request->query('user_id'),
            'status' => $request->query('status'),
            'date' => $request->query('date'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
        ];

        $records = DigitalAttendanceRecord::query()
            ->with('user:id,name,username')
            ->filter($filters)
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (DigitalAttendanceRecord $r) => $this->presentAdmin($r));

        // Summary tiles follow the exact same filters as the list above (voided rows
        // are excluded from the day/hour numbers but counted on their own).
        $agg = DigitalAttendanceRecord::query()
            ->filter($filters)
            ->selectRaw(
                "count(*) as total_count,
                sum(case when status = ? then 1 else 0 end) as in_count,
                sum(case when status = ? then 1 else 0 end) as missing_count,
                sum(case when status = ? then 1 else 0 end) as voided_count,
                sum(case when status <> ? and is_late = 1 then 1 else 0 end) as late_count,
                sum(case when status <> ? then coalesce(worked_minutes, 0) else 0 end) as minutes_sum",
                [
                    DigitalAttendanceRecord::STATUS_CLOCKED_IN,
                    DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT,
                    DigitalAttendanceRecord::STATUS_VOIDED,
                    DigitalAttendanceRecord::STATUS_VOIDED,
                    DigitalAttendanceRecord::STATUS_VOIDED,
                ]
            )
            ->first();
        $recordMinutes = (int) ($agg->minutes_sum ?? 0);

        return Inertia::render('DigitalAttendance/Admin/Records', [
            'records' => new DigitalAttendanceRecordCollection($records),
            'stats' => [
                'total' => (int) ($agg->total_count ?? 0),
                'clocked_in' => (int) ($agg->in_count ?? 0),
                'late' => (int) ($agg->late_count ?? 0),
                'missing_clock_out' => (int) ($agg->missing_count ?? 0),
                'voided' => (int) ($agg->voided_count ?? 0),
                'hours_label' => sprintf('%dh %02dm', intdiv($recordMinutes, 60), $recordMinutes % 60),
            ],
            'filters' => $filters,
            'statuses' => DigitalAttendanceRecord::STATUSES,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'canViewLocation' => $request->user()->can('view-digital-attendance-location'),
            'canCorrect' => $request->user()->can('correct-digital-attendance'),
            'canVoid' => $request->user()->can('void-digital-attendance'),
        ]);
    }

    public function show(DigitalAttendanceRecord $record)
    {
        $this->authorize('read-all-digital-attendance');

        $record->load(['user:id,name,username', 'events.actor:id,name']);

        return response()->json([
            'record' => $this->presentAdmin($record, true),
            'events' => $record->events->map(fn (DigitalAttendanceEvent $e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'description' => $e->description,
                'old_value' => $e->old_value,
                'new_value' => $e->new_value,
                'metadata' => $e->metadata,
                'actor' => optional($e->actor)->name,
                'created_at' => $e->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function correct(Request $request, DigitalAttendanceRecord $record)
    {
        $this->authorize('correct-digital-attendance');

        $data = $request->validate([
            'clock_in_at' => 'nullable|date',
            'clock_out_at' => 'nullable|date|after:clock_in_at',
            'reason' => 'required|string|max:500',
        ]);

        $old = [
            'clock_in_at' => optional($record->clock_in_at)->toIso8601String(),
            'clock_out_at' => optional($record->clock_out_at)->toIso8601String(),
            'status' => $record->status,
        ];

        $clockIn = $data['clock_in_at'] ?? $record->clock_in_at;
        $clockOut = $data['clock_out_at'] ?? $record->clock_out_at;
        $workedMinutes = $clockIn && $clockOut ? max(0, \Carbon\Carbon::parse($clockIn)->diffInMinutes(\Carbon\Carbon::parse($clockOut))) : $record->worked_minutes;
        $status = $clockOut ? DigitalAttendanceRecord::STATUS_CLOCKED_OUT : ($clockIn ? DigitalAttendanceRecord::STATUS_CLOCKED_IN : $record->status);

        $record->update([
            'clock_in_at' => $clockIn,
            'clock_out_at' => $clockOut,
            'worked_minutes' => $workedMinutes,
            'status' => $status,
            'corrected' => true,
        ]);

        DigitalAttendanceEvent::record($record, DigitalAttendanceEvent::ADMIN_CORRECTION, $data['reason'], [
            'old_value' => $old,
            'new_value' => [
                'clock_in_at' => optional($record->clock_in_at)->toIso8601String(),
                'clock_out_at' => optional($record->clock_out_at)->toIso8601String(),
                'status' => $record->status,
            ],
        ]);

        log_activity(__('Digital Attendance record corrected.'), ['reason' => $data['reason']], $record, 'DigitalAttendanceRecord');

        if ($record->user) {
            \App\Services\DigitalAttendanceNotifier::report('record_corrected', $record, $record->user, $request->user(), __('Attendance corrected: :n', ['n' => $record->user->name]), __('The attendance of :n on :d was corrected by :a. Reason: :r', ['n' => $record->user->name, 'd' => $record->attendance_date->format('d M Y'), 'a' => $request->user()->name, 'r' => $data['reason']]), ['reason' => $data['reason']], true);
        }

        return back()->with('message', __('Attendance record corrected.'));
    }

    public function void(Request $request, DigitalAttendanceRecord $record)
    {
        $this->authorize('void-digital-attendance');

        $data = $request->validate(['reason' => 'required|string|max:500']);

        $old = ['status' => $record->status];

        $record->update([
            'status' => DigitalAttendanceRecord::STATUS_VOIDED,
            'voided_at' => now(),
            'voided_by' => $request->user()->id,
            'void_reason' => $data['reason'],
        ]);

        DigitalAttendanceEvent::record($record, DigitalAttendanceEvent::ADMIN_VOID, $data['reason'], [
            'old_value' => $old,
            'new_value' => ['status' => DigitalAttendanceRecord::STATUS_VOIDED],
        ]);

        log_activity(__('Digital Attendance record voided.'), ['reason' => $data['reason']], $record, 'DigitalAttendanceRecord');

        if ($record->user) {
            \App\Services\DigitalAttendanceNotifier::report('record_voided', $record, $record->user, $request->user(), __('Attendance voided: :n', ['n' => $record->user->name]), __('The attendance of :n on :d was voided by :a. Reason: :r', ['n' => $record->user->name, 'd' => $record->attendance_date->format('d M Y'), 'a' => $request->user()->name, 'r' => $data['reason']]), ['reason' => $data['reason']], true);
        }

        return back()->with('message', __('Attendance record voided.'));
    }

    public function currentlyClockedIn(Request $request)
    {
        $this->authorize('read-all-digital-attendance');

        $records = DigitalAttendanceRecord::query()
            ->with('user:id,name,username,last_seen_at')
            ->where('status', DigitalAttendanceRecord::STATUS_CLOCKED_IN)
            ->whereDate('attendance_date', now()->toDateString())
            ->orderBy('clock_in_at')
            ->get();

        return response()->json([
            'records' => $records->map(fn (DigitalAttendanceRecord $r) => [
                'id' => $r->id,
                'user' => $r->user->only(['id', 'name']),
                'clock_in_at' => $r->clock_in_at->toIso8601String(),
                'clock_in_label' => $r->clock_in_at->format('h:i A'),
                'worked_label' => $r->worked_label,
                'is_late' => $r->is_late,
                'presence' => $this->presenceStatus($r->user->last_seen_at),
                'location' => $r->clock_in_location_status === DigitalAttendanceRecord::LOCATION_CAPTURED
                    ? ['latitude' => $r->clock_in_latitude, 'longitude' => $r->clock_in_longitude, 'accuracy' => $r->clock_in_accuracy, 'label' => $r->clock_in_location_label]
                    : null,
                'ip' => $r->clock_in_ip,
                'sessions_count' => $r->sessions_count,
            ]),
        ]);
    }

    /**
     * Everyone with a recent presence heartbeat, regardless of attendance
     * status — "online" (browser session active) is a separate concept from
     * "clocked in" (spec §20/§53), so this intentionally is not filtered by
     * Digital Attendance status at all.
     */
    public function currentlyOnline(Request $request)
    {
        $this->authorize('read-all-digital-attendance');

        $users = User::query()
            ->where('is_active', true)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subMinutes(15))
            ->orderByDesc('last_seen_at')
            ->get(['id', 'name', 'last_seen_at']);

        return response()->json([
            'users' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'presence' => $this->presenceStatus($u->last_seen_at),
                'last_seen_label' => $u->last_seen_at->diffForHumans(),
            ]),
        ]);
    }

    /**
     * A quick text/emoji nudge an admin can send to anyone currently online
     * — delivered through the existing notification bell (no new push
     * infrastructure), gated behind the same permission as the "Currently
     * Online" list itself since that's the only place it's sent from.
     */
    public function buzz(Request $request)
    {
        $this->authorize('read-all-digital-attendance');

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'message' => 'required|string|max:200',
        ]);

        $target = User::findOrFail($data['user_id']);
        $sender = $request->user();

        \App\Services\DigitalAttendanceNotifier::buzz($sender, $target, $data['message']);

        log_activity(__(':admin sent a buzz to :target.', ['admin' => $sender->name, 'target' => $target->name]), $data, $sender, 'DigitalAttendanceBuzz');

        return response()->json(['ok' => true]);
    }

    private function presentAdmin(DigitalAttendanceRecord $r, bool $withLocation = false): array
    {
        $data = [
            'id' => $r->id,
            'user' => optional($r->user)->only(['id', 'name', 'username']),
            'attendance_date' => $r->attendance_date->toDateString(),
            'clock_in_at' => optional($r->clock_in_at)->toIso8601String(),
            'clock_out_at' => optional($r->clock_out_at)->toIso8601String(),
            'clock_in_label' => optional($r->clock_in_at)->format('h:i:s A'),
            'clock_out_label' => optional($r->clock_out_at)->format('h:i:s A'),
            'status' => $r->status,
            'is_late' => $r->is_late,
            'worked_minutes' => $r->worked_minutes_live,
            'worked_label' => $r->worked_label,
            'corrected' => $r->corrected,
            'clock_in_device' => $r->clock_in_device,
            'clock_in_browser' => $r->clock_in_browser,
            'clock_in_os' => $r->clock_in_os,
            'clock_in_ip' => $r->clock_in_ip,
            'clock_out_ip' => $r->clock_out_ip,
            'clock_in_location_label' => $r->clock_in_location_label,
            'clock_out_location_label' => $r->clock_out_location_label,
            'sessions_count' => $r->sessions_count,
            'first_clock_in_at' => optional($r->first_clock_in_at)->toIso8601String(),
        ];

        if ($withLocation) {
            $data['clock_in_latitude'] = $r->clock_in_latitude;
            $data['clock_in_longitude'] = $r->clock_in_longitude;
            $data['clock_in_accuracy'] = $r->clock_in_accuracy;
            $data['clock_out_latitude'] = $r->clock_out_latitude;
            $data['clock_out_longitude'] = $r->clock_out_longitude;
            $data['clock_out_accuracy'] = $r->clock_out_accuracy;
        }

        return $data;
    }

    public static function presenceStatusFor(?\Carbon\Carbon $lastSeenAt): string
    {
        if (! $lastSeenAt) {
            return 'NOT_ACTIVE';
        }
        $minutes = now()->diffInMinutes($lastSeenAt);
        if ($minutes <= 3) {
            return 'ACTIVE_NOW';
        }
        if ($minutes <= 15) {
            return 'RECENTLY_ACTIVE';
        }

        return 'NOT_ACTIVE';
    }

    private function presenceStatus(?\Carbon\Carbon $lastSeenAt): string
    {
        return self::presenceStatusFor($lastSeenAt);
    }
}
