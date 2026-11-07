<?php

namespace App\Support;

use App\Models\DigitalAttendanceRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The Digital Attendance report as data: the records that match the filters of the page it was asked from (one day, a week, a range, one
 * employee, a search), with the summary figures. Print, PDF, Excel and e-mail all read it, so the four always show the same report.
 */
class AttendanceReport
{
    public const FILTERS = ['user_id', 'status', 'date', 'date_from', 'date_to', 'search'];

    /** The limit of rows in one report (the page, the PDF and the e-mail share it). */
    public const LIMIT = 3000;

    /** @return array{records: \Illuminate\Support\Collection, filters: array, employee: ?User, period: string, summary: array, truncated: bool} */
    public static function build(array $input): array
    {
        $filters = array_filter(array_intersect_key($input, array_flip(self::FILTERS)), fn ($v) => $v !== null && $v !== '');
        $employee = ! empty($filters['user_id']) ? User::query()->withTrashed()->select('id', 'name', 'username', 'email')->find($filters['user_id']) : null;

        $records = DigitalAttendanceRecord::query()->with('user:id,name,username')->active()->filter($filters)
            ->orderBy('attendance_date')->orderBy('clock_in_at')->limit(self::LIMIT + 1)->get();
        $truncated = $records->count() > self::LIMIT;
        $records = $records->take(self::LIMIT)
            ->sortBy(fn (DigitalAttendanceRecord $r) => $r->attendance_date->toDateString() . '|' . mb_strtolower((string) optional($r->user)->name) . '|' . optional($r->clock_in_at)->format('H:i:s'))
            ->values();

        $minutes = fn (DigitalAttendanceRecord $r) => (int) ($r->worked_minutes ?? $r->worked_minutes_live);
        $total = $records->sum($minutes);
        $days = $records->count();

        return [
            'records' => $records,
            'filters' => $filters,
            'employee' => $employee,
            'period' => self::period($filters),
            'truncated' => $truncated,
            'summary' => [
                'employees' => $records->pluck('user_id')->unique()->count(),
                'days' => $days,
                'total_minutes' => $total,
                'total_label' => self::hm($total),
                'average_label' => self::hm($days ? intdiv($total, $days) : 0),
                'late' => $records->where('is_late', true)->count(),
                'missing' => $records->where('status', DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT)->count(),
            ],
        ];
    }

    public static function hm(int $minutes): string
    {
        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }

    /** "07 Nov 2026" / "01 Nov 2026 to 07 Nov 2026" / "All dates". */
    public static function period(array $filters): string
    {
        $d = fn ($v) => Carbon::parse($v)->format('d M Y');
        if (! empty($filters['date'])) {
            return $d($filters['date']);
        }
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        if ($from && $to) {
            return $from === $to ? $d($from) : $d($from) . ' to ' . $d($to);
        }

        return $from ? 'From ' . $d($from) : ($to ? 'Up to ' . $d($to) : 'All dates');
    }

    /** What a row's status is called in the report: Late wins over a normal finish, a missing clock-out is always said. */
    public static function statusLabel(DigitalAttendanceRecord $r): string
    {
        if ($r->status !== DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT && $r->is_late) {
            return 'Late';
        }

        return ucwords(strtolower(str_replace('_', ' ', $r->status)));
    }
}
