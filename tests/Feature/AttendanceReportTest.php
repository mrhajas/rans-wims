<?php

namespace Tests\Feature;

use App\Models\DigitalAttendanceRecord;
use App\Support\AttendanceReport;
use Tests\TestCase;

// The attendance report names its period, formats hours and words a row's status the same way on the page, the print, the PDF and the email.
class AttendanceReportTest extends TestCase
{
    public function test_the_period_is_worded_from_the_filters(): void
    {
        $this->assertSame('07 Nov 2026', AttendanceReport::period(['date' => '2026-11-07']));
        $this->assertSame('02 Nov 2026 to 08 Nov 2026', AttendanceReport::period(['date_from' => '2026-11-02', 'date_to' => '2026-11-08']));
        $this->assertSame('02 Nov 2026', AttendanceReport::period(['date_from' => '2026-11-02', 'date_to' => '2026-11-02']));
        $this->assertSame('From 02 Nov 2026', AttendanceReport::period(['date_from' => '2026-11-02']));
        $this->assertSame('Up to 08 Nov 2026', AttendanceReport::period(['date_to' => '2026-11-08']));
        $this->assertSame('All dates', AttendanceReport::period([]));
    }

    public function test_minutes_become_hours_and_minutes(): void
    {
        $this->assertSame('0h 00m', AttendanceReport::hm(0));
        $this->assertSame('2h 05m', AttendanceReport::hm(125));
        $this->assertSame('35h 12m', AttendanceReport::hm(2112));
    }

    public function test_late_is_its_own_column_and_the_status_keeps_saying_how_the_day_ended(): void
    {
        $row = new DigitalAttendanceRecord(['status' => 'CLOCKED_OUT']);
        $row->is_late = true;
        $this->assertSame('Clocked Out', AttendanceReport::statusLabel($row));
        $this->assertTrue(AttendanceReport::isLate($row));

        $row->is_late = false;
        $this->assertFalse(AttendanceReport::isLate($row));

        $missing = new DigitalAttendanceRecord(['status' => 'MISSING_CLOCK_OUT']);
        $missing->is_late = true;
        $this->assertSame('Missing Clock Out', AttendanceReport::statusLabel($missing));
        $this->assertFalse(AttendanceReport::isLate($missing), 'a missing clock-out is told by its status, not counted late as well');
    }

    public function test_only_the_report_filters_are_taken_from_the_input(): void
    {
        $this->assertSame(['user_id', 'status', 'date', 'date_from', 'date_to', 'search'], AttendanceReport::FILTERS);
    }
}
