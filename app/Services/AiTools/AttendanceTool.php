<?php

namespace App\Services\AiTools;

use App\Models\DigitalAttendanceRecord;
use App\Models\User;

/**
 * Digital Attendance queries for ARIS. Self-service methods (my_*) only ever
 * touch the asking user's own records. Admin methods additionally require
 * read-all-digital-attendance — same permission the admin dashboard/records
 * pages enforce — so ARIS can never leak one employee's clock-in/location
 * data to another through a chat query.
 */
class AttendanceTool
{
    public function myStatus(array $input, User $user): array
    {
        $record = DigitalAttendanceRecord::query()
            ->where('user_id', $user->id)
            ->whereDate('attendance_date', now()->toDateString())
            ->orderByDesc('id')
            ->first();

        if (! $record) {
            return ['found' => true, 'clocked_in' => false, 'message' => 'You have not clocked in today.'];
        }

        return [
            'found' => true,
            'clocked_in' => $record->status === DigitalAttendanceRecord::STATUS_CLOCKED_IN,
            'status' => $record->status,
            'clock_in_at' => optional($record->clock_in_at)->format('h:i A'),
            'clock_out_at' => optional($record->clock_out_at)->format('h:i A'),
            'worked_label' => $record->worked_label,
        ];
    }

    public function myWeekHours(array $input, User $user): array
    {
        $records = DigitalAttendanceRecord::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereBetween('attendance_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])
            ->get();

        $minutes = $records->sum(fn (DigitalAttendanceRecord $r) => $r->worked_minutes_live);

        return [
            'found' => true,
            'attendance_days' => $records->count(),
            'total_label' => sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60),
        ];
    }

    public function whoIsClockedIn(array $input, User $user): array
    {
        if (! $user->can('read-all-digital-attendance')) {
            return ['error' => 'not_permitted', 'message' => 'You do not have permission to view team attendance.'];
        }

        $records = DigitalAttendanceRecord::query()
            ->with('user:id,name')
            ->where('status', DigitalAttendanceRecord::STATUS_CLOCKED_IN)
            ->whereDate('attendance_date', now()->toDateString())
            ->get();

        return [
            'found' => true,
            'count' => $records->count(),
            'people' => $records->map(fn (DigitalAttendanceRecord $r) => [
                'name' => optional($r->user)->name,
                'clock_in_at' => optional($r->clock_in_at)->format('h:i A'),
                'worked_label' => $r->worked_label,
            ])->values(),
        ];
    }

    public function todayAttendance(array $input, User $user): array
    {
        if (! $user->can('read-all-digital-attendance')) {
            return ['error' => 'not_permitted', 'message' => 'You do not have permission to view team attendance.'];
        }

        $records = DigitalAttendanceRecord::query()
            ->with('user:id,name')
            ->active()
            ->whereDate('attendance_date', now()->toDateString())
            ->get();

        return [
            'found' => true,
            'count' => $records->count(),
            'rows' => $records->map(fn (DigitalAttendanceRecord $r) => [
                'name' => optional($r->user)->name,
                'clock_in_at' => optional($r->clock_in_at)->format('h:i A'),
                'clock_out_at' => optional($r->clock_out_at)->format('h:i A'),
                'status' => $r->status,
            ])->values(),
        ];
    }

    public function missingClockOuts(array $input, User $user): array
    {
        if (! $user->can('read-all-digital-attendance')) {
            return ['error' => 'not_permitted', 'message' => 'You do not have permission to view team attendance.'];
        }

        $records = DigitalAttendanceRecord::query()
            ->with('user:id,name')
            ->where('status', DigitalAttendanceRecord::STATUS_MISSING_CLOCK_OUT)
            ->whereDate('attendance_date', '>=', now()->subDays(7)->toDateString())
            ->orderByDesc('attendance_date')
            ->get();

        return [
            'found' => true,
            'count' => $records->count(),
            'people' => $records->map(fn (DigitalAttendanceRecord $r) => [
                'name' => optional($r->user)->name,
                'date' => $r->attendance_date->toDateString(),
                'clock_in_at' => optional($r->clock_in_at)->format('h:i A'),
            ])->values(),
        ];
    }
}
