<?php

namespace App\Http\Controllers\DigitalAttendance;

use App\Http\Controllers\Controller;
use App\Models\DigitalAttendanceRecord;
use App\Mail\ReportMail;
use App\Models\User;
use App\Support\AttendanceReport;
use App\Support\PrintDocuments;
use App\Support\PrintQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            // an address is only sent to the page when this person may email the report (it is shown for the one employee chosen)
            'users' => User::query()->orderBy('name')->get($request->user()->can('email-digital-attendance-reports') ? ['id', 'name', 'username', 'email'] : ['id', 'name', 'username']),
            'canEmail' => $request->user()->can('email-digital-attendance-reports'),
        ]);
    }

    public function daily(Request $request)
    {
        $this->authorize('read-digital-attendance-reports');

        $date = $request->query('date', now()->toDateString());
        $rows = $this->rowsFor(['date' => $date, 'user_id' => $request->query('user_id')]);

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
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
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
                    'late' => AttendanceReport::isLate($r),
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
                'average_label' => AttendanceReport::hm($days > 0 ? (int) round($totalMinutes / $days) : 0),
                'total_minutes_all' => $totalMinutes,
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
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
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

        $report = AttendanceReport::build($this->reportFilters($request));

        log_activity(__(':user exported the Digital Attendance report to Excel.', ['user' => $request->user()->name]), $report['filters'], $request->user(), 'DigitalAttendanceReport');

        return (new FastExcel($report['records']))->download('digital-attendance-' . now()->format('Ymd-His') . '.xlsx', function (DigitalAttendanceRecord $r) {
            $minutes = (int) ($r->worked_minutes ?? $r->worked_minutes_live);

            return [
                'Date' => $r->attendance_date->toDateString(),
                'Day' => $r->attendance_date->format('D'),
                'Employee' => optional($r->user)->name,
                'Username' => optional($r->user)->username,
                'Clock In' => optional($r->clock_in_at)->format('H:i:s'),
                'Clock Out' => optional($r->clock_out_at)->format('H:i:s'),
                'Worked' => $r->worked_label,
                'Hours' => round($minutes / 60, 2),
                'Late' => AttendanceReport::isLate($r) ? 'Yes' : 'No',
                'Status' => AttendanceReport::statusLabel($r),
                'Clock-In IP' => $r->clock_in_ip,
                'Clock-Out IP' => $r->clock_out_ip,
                'Device' => trim(($r->clock_in_device ?? '') . ' / ' . ($r->clock_in_browser ?? ''), ' /'),
            ];
        });
    }

    public function print(Request $request)
    {
        $this->authorize('read-digital-attendance-reports');

        $report = AttendanceReport::build($this->reportFilters($request));
        log_activity(__(':user printed the Digital Attendance report.', ['user' => $request->user()->name]), $report['filters'], $request->user(), 'DigitalAttendanceReport');

        return view('print.document', $this->documentData($request, $report, false) + ['autoPrint' => true]);
    }

    public function pdf(Request $request)
    {
        $this->authorize('export-digital-attendance');

        $report = AttendanceReport::build($this->reportFilters($request));
        log_activity(__(':user downloaded the Digital Attendance report as PDF.', ['user' => $request->user()->name]), $report['filters'], $request->user(), 'DigitalAttendanceReport');

        return Pdf::loadView('print.document', $this->documentData($request, $report, true))->setPaper('a4')->download($this->fileName($report) . '.pdf');
    }

    /**
     * Sends the report (the same PDF the Download button makes, for the same filters) by e-mail: to the employee it is about (their own address on
     * their profile) and/or to the addresses the sender typed.
     */
    public function email(Request $request)
    {
        $this->authorize('email-digital-attendance-reports');
        $this->authorize('read-digital-attendance-reports');

        $data = $request->validate([
            'to_employee' => 'sometimes|boolean',
            'addresses' => 'nullable|string|max:1000',
            'user_id' => 'nullable|integer|exists:users,id',
            'date' => 'nullable|date',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string|max:30',
            'search' => 'nullable|string|max:100',
        ]);

        $report = AttendanceReport::build($data);
        $typed = collect(preg_split('/[\s,;]+/', (string) ($data['addresses'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($a) => mb_strtolower(trim($a)))->unique()->values();
        $bad = $typed->reject(fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL));
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('These are not valid email addresses: :list', ['list' => $bad->implode(', ')])], 422);
        }
        if ($typed->count() > 10) {
            return response()->json(['message' => __('Please send to 10 addresses or fewer at a time.')], 422);
        }

        $recipients = $typed;
        if (! empty($data['to_employee'])) {
            $employee = $report['employee'];
            if (! $employee) {
                return response()->json(['message' => __('Choose an employee first to send the report to them.')], 422);
            }
            if (! filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['message' => __(':name has no email address on their profile.', ['name' => $employee->name])], 422);
            }
            $recipients = $recipients->push(mb_strtolower($employee->email))->unique()->values();
        }
        if ($recipients->isEmpty()) {
            return response()->json(['message' => __('Choose who should receive it: the employee, or type an email address.')], 422);
        }
        if ($report['summary']['days'] === 0) {
            return response()->json(['message' => __('There are no attendance records for these filters, so there is nothing to send.')], 422);
        }

        @ini_set('memory_limit', '512M');
        $pdf = Pdf::loadView('print.document', $this->documentData($request, $report, true))->setPaper('a4')->output();
        $title = 'Attendance report, ' . $report['period'] . ($report['employee'] ? ' - ' . $report['employee']->name : '');

        $sent = [];
        $failed = [];
        foreach ($recipients as $address) {
            try {
                Mail::to($address)->send(new ReportMail($title, $pdf, $this->fileName($report) . '.pdf', optional($request->user()->account)->name));
                $sent[] = $address;
            } catch (\Throwable $e) {
                $failed[] = $address;
                Log::error('Attendance report email failed', ['email' => $address, 'error' => $e->getMessage()]);
            }
        }

        log_activity(__(':user emailed the Digital Attendance report.', ['user' => $request->user()->name]), ['filters' => $report['filters'], 'sent' => $sent, 'failed' => $failed], $request->user(), 'DigitalAttendanceReport');

        if (! $sent) {
            return response()->json(['message' => __('The email could not be sent. Please try again, or check the mail settings.')], 500);
        }

        return response()->json([
            'status' => $failed ? 'partial' : 'ok',
            'sent' => $sent, 'failed' => $failed,
            'message' => __('Sent to :list', ['list' => implode(', ', $sent)]) . ($failed ? ' ' . __('Could not send to: :list', ['list' => implode(', ', $failed)]) : ''),
        ]);
    }

    private function reportFilters(Request $request): array
    {
        return $request->all(AttendanceReport::FILTERS);
    }

    private function fileName(array $report): string
    {
        return 'attendance-' . now()->format('Ymd-His') . ($report['employee'] ? '-' . \Illuminate\Support\Str::slug($report['employee']->name) : '');
    }

    private function documentData(Request $request, array $report, bool $isPdf): array
    {
        return [
            'doc' => PrintDocuments::attendanceReport($report, optional($request->user())->name),
            'isPdf' => $isPdf,
            'companyName' => optional($request->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('digital-attendance-reports.hub')),
            'pdfUrl' => route('digital-attendance-reports.pdf', $report['filters']),
        ];
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
                'minutes' => (int) ($r->worked_minutes ?? $r->worked_minutes_live),
                'status' => $r->status,
                'late' => AttendanceReport::isLate($r),
            ])
            ->values()
            ->all();
    }
}
