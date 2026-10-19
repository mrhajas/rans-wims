<?php

use App\Support\Workflow\WorkflowModules as W;

// Starting values reproduce the Digital Attendance notices: a bell for the person whose attendance it is. "The person the notice is for"
// is the person who forgot to clock out, has been clocked in for long, has not clocked in, or the person an admin buzzes.
$sms = "WIMS - #title\n#message";
$adm = ['enabled' => false, 'roles' => ['Super Admin', 'Admin'], 'bell' => true, 'sms_text' => $sms];

return [
    'key' => 'digital_attendance', 'path' => 'digital-attendance/workflow', 'page_route' => 'digital-attendance.workflow', 'update_route' => 'digital-attendance.workflow.update',
    'title' => 'Digital Attendance Alerts', 'subtitle' => 'Decide who is told about attendance events, on which channels and with which words.',
    'record' => 'attendance record', 'owner_label' => 'the person whose attendance it is (or who is buzzed)',
    'events' => [
        'missing_clock_out' => W::ev('Missing Clock-Out', 'A session from a previous day was never clocked out; it is flagged and the person is told.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'clock']),
        'long_session' => W::ev('Long Session', 'Still clocked in more than 12 hours after clocking in; sent once.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'clock']),
        'clock_in_reminder' => W::ev('Clock-In Reminder', 'A person who can clock in has not clocked in yet after the late threshold has passed (once per person per day).', ['owner' => true, 'sms_text' => $sms, 'icon' => 'bell']),
        'buzz' => W::ev('Buzz', 'An admin sends a quick message to someone who is online, from the Currently Online list.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'bell']),
        // what an administrator may want to be told (off until a Super Admin switches it on)
        'clocked_in' => W::ev('Clocked In', 'A person clocks in.', $adm + ['icon' => 'in', 'subject' => 'Clocked in: #user', 'body' => '#user clocked in at #time.']),
        'late_clock_in' => W::ev('Late Clock-In', 'A person clocks in after the late threshold of the working day.', $adm + ['icon' => 'clock', 'subject' => 'Late clock-in: #user', 'body' => '#user clocked in at #time, #late minute(s) late.']),
        'outside_area' => W::ev('Clocked In Outside the Allowed Area', 'A person clocks in away from the attendance area (when the area is set to warn rather than block).', $adm + ['icon' => 'map-pin', 'subject' => 'Clock-in outside the area: #user', 'body' => '#user clocked in at #time outside the allowed attendance area.']),
        'clocked_out' => W::ev('Clocked Out', 'A person clocks out.', $adm + ['icon' => 'out', 'subject' => 'Clocked out: #user', 'body' => '#user clocked out at #time. Worked today: #worked.']),
        'record_corrected' => W::ev('Attendance Corrected', 'An administrator corrects the clock-in or clock-out time of a record; the person it belongs to can be told.', $adm + ['owner' => true, 'icon' => 'edit', 'subject' => 'Attendance corrected: #user', 'body' => 'The attendance of #user on #date was corrected by #actor. Reason: #reason']),
        'record_voided' => W::ev('Attendance Voided', 'An administrator voids an attendance record; the person it belongs to can be told.', $adm + ['owner' => true, 'icon' => 'cross', 'subject' => 'Attendance voided: #user', 'body' => 'The attendance of #user on #date was voided by #actor. Reason: #reason']),
        'settings_changed' => W::ev('Attendance Settings Changed', 'The Digital Attendance settings (working hours, late threshold, area, switches) are changed.', $adm + ['icon' => 'cog', 'subject' => 'Attendance settings changed', 'body' => 'The Digital Attendance settings were changed by #actor.']),
    ],
    'tags' => [
        W::tag('date', 'Attendance', 'Attendance date', 'The day the notice is about.', '12 Oct 2026'),
        W::tag('user', 'Attendance', 'Person', 'The person the attendance record belongs to.', 'Neema Salum'),
        W::tag('time', 'Attendance', 'Time', 'The time of the clock-in or clock-out.', '08:12'),
        W::tag('late', 'Attendance', 'Minutes late', 'How many minutes after the late threshold the person clocked in.', '17'),
        W::tag('worked', 'Attendance', 'Time worked', 'The time worked today, once the person has clocked out.', '08h 05m'),
        W::tag('reason', 'Attendance', 'Reason', 'The reason an administrator typed (correction or void only).', 'Forgot to clock out'),
        W::tag('hours', 'Attendance', 'Hours clocked in', 'How long the person has been clocked in (long sessions).', '12'),
    ],
];
