<?php

namespace App\Http\Controllers\DigitalAttendance;

use App\Http\Controllers\Controller;
use App\Http\Resources\DigitalAttendanceRecordCollection;
use App\Models\DigitalAttendanceEvent;
use App\Models\DigitalAttendanceRecord;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DigitalAttendanceController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function show(Request $request)
    {
        $this->authorize('read-digital-attendance');

        $user = $request->user();
        $today = $this->activeOrTodayRecord($user);
        $settings = digital_attendance_settings();

        return Inertia::render('DigitalAttendance/MyAttendance', [
            'today' => $today ? $this->present($today) : null,
            'week' => $this->periodSummary($user, now()->startOfWeek(), now()->endOfWeek()),
            'month' => $this->periodSummary($user, now()->startOfMonth(), now()->endOfMonth()),
            // Deliberately NOT called "settings" — that key is already used by
            // HandleInertiaRequests::share() for the app-wide Setting object
            // (theme, sidebar_style, etc.), and an Inertia::render() prop
            // silently shadows a same-named shared prop. Under the old name
            // this broke $page.props.settings.sidebar_style app-wide on every
            // Digital Attendance page, forcing the sidebar into its flat
            // always-expanded fallback layout regardless of the user's
            // actual saved preference.
            'attendance_settings' => [
                'enabled' => $settings['digital_attendance_enabled'],
                'location_capture' => $settings['digital_attendance_location_capture'],
                'require_location' => $settings['digital_attendance_require_location'],
                'geofencing' => $settings['digital_attendance_geofencing'],
                'geofence_radius' => $settings['digital_attendance_geofence_radius'],
                'geofence_mode' => $settings['digital_attendance_geofence_mode'],
                'geofence_latitude' => $settings['digital_attendance_geofence_latitude'],
                'geofence_longitude' => $settings['digital_attendance_geofence_longitude'],
                'working_start' => $settings['digital_attendance_working_start'],
                'working_end' => $settings['digital_attendance_working_end'],
            ],
            'serverTime' => now()->toIso8601String(),
            // Always-visible "what WIMS can see about this session right
            // now" info, independent of whether today has a clock-in record
            // yet — the location/local-IP parts are filled in client-side.
            'client_context' => $this->requestContext($request),
        ]);
    }

    public function widget(Request $request)
    {
        $this->authorize('read-digital-attendance');

        $record = $this->activeOrTodayRecord($request->user());
        $settings = digital_attendance_settings();

        return response()->json([
            'today' => $record ? $this->present($record) : null,
            'settings' => [
                'enabled' => $settings['digital_attendance_enabled'],
                'working_start' => $settings['digital_attendance_working_start'],
                'working_end' => $settings['digital_attendance_working_end'],
            ],
        ]);
    }

    public function history(Request $request)
    {
        $this->authorize('read-digital-attendance');

        $filters = [
            'user_id' => $request->user()->id,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $records = DigitalAttendanceRecord::query()
            // A voided record is a superseded duplicate/correction artifact,
            // not a real day of attendance — without this, a pre-merge-fix
            // duplicate shows up as a second, confusing row for a day that
            // already has its real record.
            ->active()
            ->filter($filters)
            ->orderByDesc('attendance_date')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (DigitalAttendanceRecord $r) => $this->present($r));

        $agg = DigitalAttendanceRecord::query()
            ->active()
            ->filter($filters)
            ->selectRaw(
                'count(*) as days_count, sum(case when is_late = 1 then 1 else 0 end) as late_count, sum(case when status = ? then 1 else 0 end) as missing_count, sum(coalesce(worked_minutes, 0)) as minutes_sum',
                [DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT]
            )
            ->first();
        $historyMinutes = (int) ($agg->minutes_sum ?? 0);
        $historyDays = (int) ($agg->days_count ?? 0);
        $historyAvg = $historyDays > 0 ? intdiv($historyMinutes, $historyDays) : 0;

        return Inertia::render('DigitalAttendance/History', [
            'records' => new DigitalAttendanceRecordCollection($records),
            'stats' => [
                'days' => $historyDays,
                'late' => (int) ($agg->late_count ?? 0),
                'missing_clock_out' => (int) ($agg->missing_count ?? 0),
                'total_label' => sprintf('%dh %02dm', intdiv($historyMinutes, 60), $historyMinutes % 60),
                'average_label' => sprintf('%dh %02dm', intdiv($historyAvg, 60), $historyAvg % 60),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * Self-service print/PDF of the logged-in user's OWN attendance only —
     * force-scoped to their user_id regardless of any admin-report
     * permission, so every user can get a copy of their own history without
     * needing read-digital-attendance-reports.
     */
    public function myPrint(Request $request)
    {
        $this->authorize('read-digital-attendance');

        log_activity(__(':user printed their own Digital Attendance history.', ['user' => $request->user()->name]), [], $request->user(), 'DigitalAttendanceReport');

        return view('digital-attendance-print', $this->myReportData($request, true));
    }

    public function myPdf(Request $request)
    {
        $this->authorize('read-digital-attendance');

        log_activity(__(':user downloaded their own Digital Attendance history as PDF.', ['user' => $request->user()->name]), [], $request->user(), 'DigitalAttendanceReport');

        $pdf = Pdf::loadView('digital-attendance-print', $this->myReportData($request, false));

        return $pdf->download('my-attendance-' . now()->format('Ymd-His') . '.pdf');
    }

    private function myReportData(Request $request, bool $autoPrint): array
    {
        $filters = [
            'user_id' => $request->user()->id,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $records = DigitalAttendanceRecord::query()->with('user:id,name')->active()->filter($filters)->orderBy('attendance_date')->limit(1000)->get();
        $totalMinutes = $records->sum(fn (DigitalAttendanceRecord $r) => $r->worked_minutes ?? 0);

        return [
            'records' => $records,
            'filters' => $filters,
            'autoPrint' => $autoPrint,
            'attendanceDays' => $records->pluck('attendance_date')->map(fn ($d) => $d->toDateString())->unique()->count(),
            'totalLabel' => sprintf('%dh %02dm', intdiv($totalMinutes, 60), $totalMinutes % 60),
            'averageLabel' => $records->count() > 0 ? sprintf('%02dh %02dm', intdiv((int) ($totalMinutes / $records->count()), 60), (int) ($totalMinutes / $records->count()) % 60) : '0h 00m',
            'companyName' => optional($request->user()->account ?? null)->name,
            'generatedBy' => optional($request->user())->name,
        ];
    }

    public function calendar(Request $request)
    {
        $this->authorize('read-digital-attendance');

        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);
        $start = now()->setDate($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $records = DigitalAttendanceRecord::query()
            ->where('user_id', $request->user()->id)
            ->active()
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->map(fn (DigitalAttendanceRecord $r) => $this->present($r))
            ->values();

        return Inertia::render('DigitalAttendance/Calendar', [
            'records' => $records,
            'month' => $month,
            'year' => $year,
            // See the comment in show() — must not collide with the shared "settings" prop.
            'attendance_settings' => ['working_start' => digital_attendance_settings()['digital_attendance_working_start']],
        ]);
    }

    public function clockIn(Request $request)
    {
        $this->authorize('clock-in-digital-attendance');

        $settings = digital_attendance_settings();

        if (! $settings['digital_attendance_enabled']) {
            return back()->with('error', __('Digital Attendance is currently disabled.'));
        }

        $user = $request->user();

        $existing = DigitalAttendanceRecord::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [DigitalAttendanceRecord::STATUS_CLOCKED_IN, DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT])
            ->first();

        if ($existing && $existing->status === DigitalAttendanceRecord::STATUS_CLOCKED_IN) {
            return back()->with('error', __('You are already clocked in.'));
        }

        if ($existing && $existing->status === DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT) {
            return back()->with('error', __('You still have an open attendance session from :date that is missing a clock-out. Please ask an administrator to review and correct it before clocking in again.', ['date' => $existing->attendance_date->toFormattedDateString()]));
        }

        // A same-day record that's already CLOCKED_OUT means the user is
        // clocking in a second (or later) time today — reopen that same
        // day-record instead of creating a sibling row, so the worked time
        // accumulates under one parent record rather than splitting across
        // two "days".
        $todayRecord = DigitalAttendanceRecord::query()
            ->where('user_id', $user->id)
            ->whereDate('attendance_date', now()->toDateString())
            ->where('status', DigitalAttendanceRecord::STATUS_CLOCKED_OUT)
            ->orderByDesc('id')
            ->first();

        $data = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'location_status' => 'required|in:captured,denied,unavailable',
            'local_ip' => 'nullable|string|max:64',
        ]);

        if ($settings['digital_attendance_require_location'] && $data['location_status'] !== 'captured') {
            return back()->with('error', __('Digital Attendance requires your location to record attendance. Please allow location access and try again.'));
        }

        $geofenceResult = $this->evaluateGeofence($settings, $data);
        if ($geofenceResult === 'blocked') {
            return back()->with('error', __('You are outside the allowed attendance area.'));
        }

        $context = $this->requestContext($request);
        $now = now();
        $workStart = $settings['digital_attendance_working_start'];
        $workEnd = $settings['digital_attendance_working_end'];
        $windowStart = $now->copy()->setTimeFromTimeString($workStart);
        $windowEnd = $now->copy()->setTimeFromTimeString($workEnd);

        // Clock-out has no such gate (see clockOut() below) — someone
        // already on the clock must always be able to end their session,
        // even if working hours have since passed. Clock-in is the only
        // side this restricts, per admin-configured working hours.
        if ($now->lt($windowStart) || $now->gt($windowEnd)) {
            return back()->with('error', __('You can only clock in between :start and :end.', [
                'start' => $windowStart->format('h:i A'),
                'end' => $windowEnd->format('h:i A'),
            ]));
        }

        $lateThreshold = $settings['digital_attendance_late_threshold_minutes'];
        $expectedStart = $windowStart->copy()->addMinutes($lateThreshold);
        $isLate = $now->gt($expectedStart);

        $sessionFields = [
            'clock_in_at' => $now,
            'clock_out_at' => null,
            'status' => DigitalAttendanceRecord::STATUS_CLOCKED_IN,
            'clock_in_latitude' => $data['latitude'] ?? null,
            'clock_in_longitude' => $data['longitude'] ?? null,
            'clock_in_accuracy' => $data['accuracy'] ?? null,
            'clock_in_location_status' => $data['location_status'],
            'clock_in_location_label' => $data['location_status'] === 'captured' ? reverse_geocode($data['latitude'] ?? null, $data['longitude'] ?? null) : null,
            'clock_in_ip' => $context['ip'],
            'clock_in_local_ip' => $data['local_ip'] ?? null,
            'clock_in_user_agent' => $context['user_agent'],
            'clock_in_browser' => $context['browser'],
            'clock_in_os' => $context['os'],
            'clock_in_device' => $context['device'],
        ];

        if ($todayRecord) {
            $todayRecord->update(array_merge($sessionFields, [
                'accumulated_minutes' => (int) $todayRecord->worked_minutes,
                'sessions_count' => $todayRecord->sessions_count + 1,
                'worked_minutes' => null,
            ]));
            $record = $todayRecord;
        } else {
            $record = DigitalAttendanceRecord::create(array_merge($sessionFields, [
                'account_id' => getAccountId(),
                'user_id' => $user->id,
                'attendance_date' => $now->toDateString(),
                'first_clock_in_at' => $now,
                'is_late' => $isLate,
                'late_minutes' => $isLate ? $now->diffInMinutes($expectedStart) : null,
            ]));
        }

        DigitalAttendanceEvent::record($record, DigitalAttendanceEvent::CLOCK_IN, $todayRecord
            ? __(':name clocked in again (session :n today).', ['name' => $user->name, 'n' => $record->sessions_count])
            : __(':name clocked in.', ['name' => $user->name]), [
            'metadata' => array_merge($context, ['location_status' => $data['location_status'], 'geofence' => $geofenceResult, 'session' => $record->sessions_count]),
        ]);

        if ($data['location_status'] === 'captured') {
            DigitalAttendanceEvent::record($record, DigitalAttendanceEvent::LOCATION_CAPTURE, 'Clock-in location captured.', [
                'new_value' => ['latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'accuracy' => $data['accuracy'] ?? null],
            ]);
        }

        // tell the people the Digital Attendance workflow page says (nothing is sent until those events are switched on)
        $when = $now->format('H:i');
        \App\Services\DigitalAttendanceNotifier::report('clocked_in', $record, $user, $user, __('Clocked in: :n', ['n' => $user->name]), __(':n clocked in at :t.', ['n' => $user->name, 't' => $when]), ['time' => $when]);
        if ($isLate && ! $todayRecord) {
            $late = (int) $now->diffInMinutes($expectedStart);
            \App\Services\DigitalAttendanceNotifier::report('late_clock_in', $record, $user, $user, __('Late clock-in: :n', ['n' => $user->name]), __(':n clocked in at :t, :m minute(s) late.', ['n' => $user->name, 't' => $when, 'm' => $late]), ['time' => $when, 'late' => (string) $late]);
        }
        if ($geofenceResult === 'outside') {
            \App\Services\DigitalAttendanceNotifier::report('outside_area', $record, $user, $user, __('Clock-in outside the area: :n', ['n' => $user->name]), __(':n clocked in at :t outside the allowed attendance area.', ['n' => $user->name, 't' => $when]), ['time' => $when]);
        }

        return back()->with('message', __('Clocked in successfully.'));
    }

    public function clockOut(Request $request)
    {
        $this->authorize('clock-out-digital-attendance');

        $user = $request->user();

        $record = DigitalAttendanceRecord::query()
            ->where('user_id', $user->id)
            ->where('status', DigitalAttendanceRecord::STATUS_CLOCKED_IN)
            ->first();

        if (! $record) {
            return back()->with('error', __('You do not have an active attendance session to clock out from.'));
        }

        $data = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'location_status' => 'required|in:captured,denied,unavailable',
            'local_ip' => 'nullable|string|max:64',
        ]);

        $context = $this->requestContext($request);
        $now = now();
        $sessionMinutes = max(0, $record->clock_in_at->diffInMinutes($now));
        $workedMinutes = (int) $record->accumulated_minutes + $sessionMinutes;

        $record->update([
            'clock_out_at' => $now,
            'worked_minutes' => $workedMinutes,
            'status' => DigitalAttendanceRecord::STATUS_CLOCKED_OUT,
            'clock_out_latitude' => $data['latitude'] ?? null,
            'clock_out_longitude' => $data['longitude'] ?? null,
            'clock_out_accuracy' => $data['accuracy'] ?? null,
            'clock_out_location_status' => $data['location_status'],
            'clock_out_location_label' => $data['location_status'] === 'captured' ? reverse_geocode($data['latitude'] ?? null, $data['longitude'] ?? null) : null,
            'clock_out_ip' => $context['ip'],
            'clock_out_local_ip' => $data['local_ip'] ?? null,
            'clock_out_user_agent' => $context['user_agent'],
            'clock_out_browser' => $context['browser'],
            'clock_out_os' => $context['os'],
            'clock_out_device' => $context['device'],
        ]);

        DigitalAttendanceEvent::record($record, DigitalAttendanceEvent::CLOCK_OUT, "{$user->name} clocked out. Session {$sessionMinutes}m, {$workedMinutes}m total today.", [
            'metadata' => array_merge($context, ['location_status' => $data['location_status'], 'session_minutes' => $sessionMinutes, 'worked_minutes' => $workedMinutes]),
        ]);

        if ($data['location_status'] === 'captured') {
            DigitalAttendanceEvent::record($record, DigitalAttendanceEvent::LOCATION_CAPTURE, 'Clock-out location captured.', [
                'new_value' => ['latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'accuracy' => $data['accuracy'] ?? null],
            ]);
        }

        $when = $now->format('H:i');
        $worked = sprintf('%02dh %02dm', intdiv($workedMinutes, 60), $workedMinutes % 60);
        \App\Services\DigitalAttendanceNotifier::report('clocked_out', $record, $user, $user, __('Clocked out: :n', ['n' => $user->name]), __(':n clocked out at :t. Worked today: :w.', ['n' => $user->name, 't' => $when, 'w' => $worked]), ['time' => $when, 'worked' => $worked]);

        return back()->with('message', __('Clocked out successfully.'));
    }

    /**
     * The individual clock-in/out sessions folded into one day record —
     * derived from the append-only event log (never from mutable columns,
     * which only ever hold the latest session's raw values) so a reopened
     * day's earlier sessions stay fully reconstructable.
     */
    public function sessions(DigitalAttendanceRecord $record, Request $request)
    {
        $this->authorize('read-digital-attendance');
        abort_unless($record->user_id === $request->user()->id, 403);

        $events = $record->events()
            // The events() relation itself carries a baked-in orderByDesc('id')
            // (most-recent-first, for the admin detail modal's audit trail) —
            // reorder() clears that so ->orderBy() below actually takes effect
            // instead of just appending after it.
            ->reorder()
            ->whereIn('event_type', [DigitalAttendanceEvent::CLOCK_IN, DigitalAttendanceEvent::CLOCK_OUT])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $sessions = [];
        $current = null;

        foreach ($events as $event) {
            if ($event->event_type === DigitalAttendanceEvent::CLOCK_IN) {
                $current = [
                    'clock_in_at' => $event->created_at->toIso8601String(),
                    'clock_in_label' => $event->created_at->format('h:i A'),
                    'clock_out_at' => null,
                    'clock_out_label' => null,
                    'worked_label' => null,
                ];
            } elseif ($event->event_type === DigitalAttendanceEvent::CLOCK_OUT && $current) {
                $minutes = \Carbon\Carbon::parse($current['clock_in_at'])->diffInMinutes($event->created_at);
                $current['clock_out_at'] = $event->created_at->toIso8601String();
                $current['clock_out_label'] = $event->created_at->format('h:i A');
                $current['worked_label'] = sprintf('%02dh %02dm', intdiv($minutes, 60), $minutes % 60);
                $sessions[] = $current;
                $current = null;
            }
        }

        if ($current) {
            $sessions[] = $current;
        }

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * A live, non-persisted reverse-geocode preview for the "what WIMS can
     * see about this session" panel — the actual clock-in/out endpoints do
     * their own reverse_geocode() call independently at save time, this
     * exists purely so the page can show a real place name before the user
     * has clocked in at all.
     */
    public function previewLocation(Request $request)
    {
        $this->authorize('read-digital-attendance');

        $data = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        return response()->json(['label' => reverse_geocode($data['latitude'], $data['longitude'])]);
    }

    public function heartbeat(Request $request)
    {
        $this->authorize('read-digital-attendance');

        $request->user()->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json(['ok' => true]);
    }

    private function activeOrTodayRecord(User $user): ?DigitalAttendanceRecord
    {
        return DigitalAttendanceRecord::query()
            ->where('user_id', $user->id)
            // A voided record must never be presented as "today's status" —
            // it's been explicitly invalidated, so it should never outrank a
            // real record, and if it's the only same-day row, the user should
            // see "haven't clocked in" rather than stale voided data.
            ->active()
            ->where(function ($q) {
                $q->whereIn('status', [DigitalAttendanceRecord::STATUS_CLOCKED_IN, DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT])
                    ->orWhereDate('attendance_date', now()->toDateString());
            })
            // An actually-open session must always win over a same-day closed
            // one, however the rows sort by id — a closed row can carry a
            // higher id than an open row that was later reopened via an
            // UPDATE (its id doesn't change on reopen), which made a plain
            // `orderByDesc('id')` show "not clocked in" while clockIn()'s own
            // guard (which correctly checks status, unscoped by date) still
            // found the open row and refused with "already clocked in".
            ->orderByRaw("CASE WHEN status IN ('".DigitalAttendanceRecord::STATUS_CLOCKED_IN."', '".DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT."') THEN 0 ELSE 1 END")
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->first();
    }

    private function periodSummary(User $user, $start, $end): array
    {
        $records = DigitalAttendanceRecord::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $days = $records->pluck('attendance_date')->map(fn ($d) => $d->toDateString())->unique()->count();
        $totalMinutes = $records->sum(fn (DigitalAttendanceRecord $r) => $r->worked_minutes_live);

        return [
            'days' => $days,
            'total_minutes' => $totalMinutes,
            'total_label' => sprintf('%02dh %02dm', intdiv($totalMinutes, 60), $totalMinutes % 60),
            'average_minutes' => $days > 0 ? (int) round($totalMinutes / $days) : 0,
        ];
    }

    private function present(DigitalAttendanceRecord $r): array
    {
        // Backfill on the fly for records saved before reverse-geocoding
        // existed, or where the lookup failed at clock-in time — cached for
        // 30 days by reverse_geocode(), so this only costs a real request
        // once per place.
        $locationLabel = $r->clock_in_location_label;
        if (! $locationLabel && $r->clock_in_location_status === DigitalAttendanceRecord::LOCATION_CAPTURED && $r->clock_in_latitude) {
            $locationLabel = reverse_geocode($r->clock_in_latitude, $r->clock_in_longitude);
        }

        return [
            'id' => $r->id,
            'attendance_date' => $r->attendance_date->toDateString(),
            'clock_in_at' => optional($r->clock_in_at)->toIso8601String(),
            'clock_out_at' => optional($r->clock_out_at)->toIso8601String(),
            'status' => $r->status,
            'is_late' => $r->is_late,
            'worked_minutes' => $r->worked_minutes_live,
            'worked_label' => $r->worked_label,
            'clock_in_location_status' => $r->clock_in_location_status,
            'clock_in_location_label' => $locationLabel,
            'clock_in_latitude' => $r->clock_in_latitude,
            'clock_in_longitude' => $r->clock_in_longitude,
            'clock_in_ip' => $r->clock_in_ip,
            'clock_in_local_ip' => $r->clock_in_local_ip,
            'clock_out_location_label' => $r->clock_out_location_label,
            'clock_out_ip' => $r->clock_out_ip,
            'clock_out_local_ip' => $r->clock_out_local_ip,
            'clock_in_device' => $r->clock_in_device,
            'clock_in_browser' => $r->clock_in_browser,
            'first_clock_in_at' => optional($r->first_clock_in_at)->toIso8601String(),
            'sessions_count' => $r->sessions_count,
            'corrected' => $r->corrected,
        ];
    }

    private function requestContext(Request $request): array
    {
        $ua = (string) $request->userAgent();
        $info = user_agent_info($ua);

        return [
            'ip' => $request->ip(),
            'user_agent' => $ua,
            'browser' => $info['browser'],
            'os' => $info['os'],
            'device' => $info['device'],
        ];
    }

    /**
     * Returns 'inside', 'outside', 'blocked' (outside + block mode), or null
     * (geofencing off / no coordinates to check). Never blocks unless the
     * administrator has explicitly enabled both geofencing and block mode.
     */
    private function evaluateGeofence(array $settings, array $data): ?string
    {
        if (! $settings['digital_attendance_geofencing'] || $settings['digital_attendance_geofence_latitude'] === null) {
            return null;
        }
        if (empty($data['latitude']) || empty($data['longitude'])) {
            return null;
        }

        $distance = $this->haversineMeters(
            $settings['digital_attendance_geofence_latitude'],
            $settings['digital_attendance_geofence_longitude'],
            $data['latitude'],
            $data['longitude']
        );

        $inside = $distance <= $settings['digital_attendance_geofence_radius'];
        if ($inside) {
            return 'inside';
        }

        return $settings['digital_attendance_geofence_mode'] === 'block' ? 'blocked' : 'outside';
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
