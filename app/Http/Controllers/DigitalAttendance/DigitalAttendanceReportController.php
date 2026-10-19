<?php

namespace App\Http\Controllers\DigitalAttendance;

use App\Http\Controllers\Controller;
use App\Models\DigitalAttendanceRecord;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

class DigitalAttendanceReportController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function hub(Request $request)
    {
        $this->authorize('read-digital-attendance-reports');

        return Inertia::render('DigitalAttendance/Admin/Reports', [
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function daily(Request $request)
    {
        $this->authorize('read-digital-attendance-reports');

        $date = $request->query('date', now()->toDateString());
        $rows = $this->rowsFor(['date' => $date]);

        return response()->json(['date' => $date, 'rows' => $rows]);
    }

    public function weekly(Request $request)
    {
        $this->authorize('read-digital-attendance-reports');

        $start = $request->query('start') ? \Carbon\Carbon::parse($request->query('start'))->startOfWeek() : now()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $records = DigitalAttendanceRecord::query()
            ->with('user:id,name')
            ->active()
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('user_id');

        $days = collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i)->toDateString());

        $rows = $records->map(function ($userRecords, $userId) use ($days) {
            $byDate = $userRecords->keyBy(fn (DigitalAttendanceRecord $r) => $r->attendance_date->toDateString());
            $daily = $days->map(function ($d) use ($byDate) {
                if (! $byDate->has($d)) {
                    return null;
                }
                $r = $byDate[$d];
                $minutes = $r->worked_minutes ?? $r->worked_minutes_live;

                return [
                    'hours' => round($minutes / 60, 1),
                    'clock_in' => optional($r->clock_in_at)->format('h:i A'),
                    'clock_out' => optional($r->clock_out_at)->format('h:i A'),
                    'worked_label' => $r->worked_label,
                    'status' => $r->status,
                    'sessions_count' => $r->sessions_count,
                ];
            });
            $total = $daily->filter()->sum('hours');

            return [
                'user' => optional($userRecords->first()->user)->only(['id', 'name']),
                'daily' => $daily,
                'total_hours' => round($total, 1),
                'attendance_days' => $byDate->count(),
                'late_count' => $userRecords->where('is_late', true)->count(),
                'missing_count' => $userRecords->where('status', DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT)->count(),
            ];
        })->values();

        return response()->json(['start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => $days, 'rows' => $rows]);
    }

    public function custom(Request $request)
    {
        $this->authorize('read-digital-attendance-reports');

        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'user_id' => 'nullable|exists:users,id',
        ]);

        // This report aggregates in PHP over every row in range, so an unbounded
        // span (e.g. several years) would load the whole attendance table into memory.
        abort_if(
            \Illuminate\Support\Carbon::parse($data['from'])->diffInDays(\Illuminate\Support\Carbon::parse($data['to'])) > 366,
            422,
            __('Please choose a date range of one year or less.')
        );

        $query = DigitalAttendanceRecord::query()
            ->with('user:id,name')
            ->active()
            ->whereBetween('attendance_date', [$data['from'], $data['to']])
            ->when($data['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id));

        $records = $query->get()->groupBy('user_id');

        $rows = $records->map(function ($userRecords) {
            $totalMinutes = $userRecords->sum(fn (DigitalAttendanceRecord $r) => $r->worked_minutes ?? $r->worked_minutes_live);
            // Distinct calendar dates, not row count — the same-day merge
            // fix keeps this 1:1 under normal operation, but counting rows
            // directly would double-count if a stray same-day duplicate ever
            // exists (e.g. pre-fix historical data).
            $days = $userRecords->pluck('attendance_date')->map(fn ($d) => $d->toDateString())->unique()->count();

            return [
                'user' => optional($userRecords->first()->user)->only(['id', 'name']),
                'attendance_days' => $days,
                'total_minutes' => $totalMinutes,
                'total_label' => sprintf('%dh %02dm', intdiv($totalMinutes, 60), $totalMinutes % 60),
                'average_minutes' => $days > 0 ? (int) round($totalMinutes / $days) : 0,
                'late_count' => $userRecords->where('is_late', true)->count(),
                'missing_count' => $userRecords->where('status', DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT)->count(),
            ];
        })->values();

        return response()->json(['from' => $data['from'], 'to' => $data['to'], 'rows' => $rows]);
    }

    public function location(Request $request)
    {
        $this->authorize('view-digital-attendance-location');

        $filters = $request->all('user_id', 'date_from', 'date_to');

        $records = DigitalAttendanceRecord::query()
            ->with('user:id,name')
            ->active()
            ->filter($filters)
            ->orderByDesc('attendance_date')
            ->limit(500)
            ->get()
            ->map(function (DigitalAttendanceRecord $r) {
                // Backfill a label on the fly for older records saved before
                // reverse-geocoding existed — reverse_geocode() caches for 30
                // days, so this only pays the lookup cost once per place.
                $label = $r->clock_in_location_label;
                if (! $label && $r->clock_in_latitude) {
                    $label = reverse_geocode($r->clock_in_latitude, $r->clock_in_longitude);
                }

                return [
                    'id' => $r->id,
                    'user' => optional($r->user)->name,
                    'date' => $r->attendance_date->toDateString(),
                    'clock_in_location' => $r->clock_in_latitude ? "{$r->clock_in_latitude}, {$r->clock_in_longitude}" : null,
                    'clock_in_location_label' => $label,
                    'clock_out_location' => $r->clock_out_latitude ? "{$r->clock_out_latitude}, {$r->clock_out_longitude}" : null,
                    'clock_out_location_label' => $r->clock_out_location_label,
                    'clock_in_accuracy' => $r->clock_in_accuracy,
                    'clock_in_ip' => $r->clock_in_ip,
                    'clock_out_ip' => $r->clock_out_ip,
                    'clock_in_device' => trim(($r->clock_in_device ?? '') . ' / ' . ($r->clock_in_browser ?? ''), ' /'),
                    'clock_in_latitude' => $r->clock_in_latitude,
                    'clock_in_longitude' => $r->clock_in_longitude,
                ];
            });

        return response()->json(['rows' => $records]);
    }

    public function device(Request $request)
    {
        $this->authorize('view-digital-attendance-location');

        $records = DigitalAttendanceRecord::query()
            ->with('user:id,name,last_seen_at')
            ->active()
            ->where(function ($q) {
                $q->where('status', DigitalAttendanceRecord::STATUS_CLOCKED_IN)
                    ->orWhere('attendance_date', now()->toDateString());
            })
            ->limit(200)
            ->get()
            ->unique('user_id')
            ->map(fn (DigitalAttendanceRecord $r) => [
                'user' => optional($r->user)->name,
                'browser' => $r->clock_in_browser,
                'os' => $r->clock_in_os,
                'device' => $r->clock_in_device,
                'last_seen' => optional(optional($r->user)->last_seen_at)->diffForHumans(),
                'status' => $r->status,
            ])
            ->values();

        return response()->json(['rows' => $records]);
    }

    public function export(Request $request)
    {
        $this->authorize('export-digital-attendance');

        $filters = $this->reportFilters($request);
        $rows = DigitalAttendanceRecord::query()->with('user:id,name,username')->active()->filter($filters)->limit(10000)->get();

        log_activity(__(':user exported the Digital Attendance report to Excel.', ['user' => $request->user()->name]), $filters, $request->user(), 'DigitalAttendanceReport');

        return (new FastExcel($rows))->download('digital-attendance-' . now()->format('Ymd-His') . '.xlsx', function (DigitalAttendanceRecord $r) {
            return [
                'Date' => $r->attendance_date->toDateString(),
                'Employee' => optional($r->user)->name,
                'Clock In' => optional($r->clock_in_at)->format('H:i:s'),
                'Clock Out' => optional($r->clock_out_at)->format('H:i:s'),
                'Worked' => $r->worked_label,
                'Status' => $r->status,
                'Late' => $r->is_late ? 'Yes' : 'No',
                'Clock-In IP' => $r->clock_in_ip,
                'Clock-Out IP' => $r->clock_out_ip,
                'Device' => trim(($r->clock_in_device ?? '') . ' / ' . ($r->clock_in_browser ?? ''), ' /'),
            ];
        });
    }

    public function print(Request $request)
    {
        $this->authorize('read-digital-attendance-reports');

        log_activity(__(':user printed the Digital Attendance report.', ['user' => $request->user()->name]), $this->reportFilters($request), $request->user(), 'DigitalAttendanceReport');

        return view('digital-attendance-print', $this->viewData($request, true));
    }

    public function pdf(Request $request)
    {
        $this->authorize('export-digital-attendance');

        log_activity(__(':user downloaded the Digital Attendance report as PDF.', ['user' => $request->user()->name]), $this->reportFilters($request), $request->user(), 'DigitalAttendanceReport');

        $pdf = Pdf::loadView('digital-attendance-print', $this->viewData($request, false));

        return $pdf->download('digital-attendance-' . now()->format('Ymd-His') . '.pdf');
    }

    private function reportFilters(Request $request): array
    {
        return $request->all('user_id', 'status', 'date', 'date_from', 'date_to', 'search');
    }

    private function rowsFor(array $filters): array
    {
        return DigitalAttendanceRecord::query()
            ->with('user:id,name')
            ->active()
            ->filter($filters)
            ->orderBy('clock_in_at')
            ->get()
            ->map(fn (DigitalAttendanceRecord $r) => [
                'id' => $r->id,
                'user' => optional($r->user)->name,
                'clock_in_label' => optional($r->clock_in_at)->format('H:i'),
                'clock_out_label' => optional($r->clock_out_at)->format('H:i'),
                'worked_label' => $r->worked_label,
                'status' => $r->status,
            ])
            ->values()
            ->all();
    }

    private function viewData(Request $request, bool $autoPrint): array
    {
        $filters = $this->reportFilters($request);
        $records = DigitalAttendanceRecord::query()->with('user:id,name')->active()->filter($filters)->orderBy('attendance_date')->limit(3000)->get();
        $totalMinutes = $records->sum(fn (DigitalAttendanceRecord $r) => $r->worked_minutes ?? 0);

        return [
            'records' => $records,
            'filters' => $filters,
            'autoPrint' => $autoPrint,
            'attendanceDays' => $records->count(),
            'totalLabel' => sprintf('%dh %02dm', intdiv($totalMinutes, 60), $totalMinutes % 60),
            'averageLabel' => $records->count() > 0 ? sprintf('%02dh %02dm', intdiv((int) ($totalMinutes / $records->count()), 60), (int) ($totalMinutes / $records->count()) % 60) : '0h 00m',
            'companyName' => optional($request->user()->account ?? null)->name,
            'generatedBy' => optional($request->user())->name,
        ];
    }
}
