<?php

namespace App\Http\Controllers\DigitalAttendance;

use App\Http\Controllers\Controller;
use App\Models\DigitalAttendanceEvent;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DigitalAttendanceSettingsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function edit(Request $request)
    {
        $this->authorize('manage-digital-attendance-settings');

        return Inertia::render('DigitalAttendance/Admin/Settings', [
            // Not "settings" — that key collides with the app-wide shared
            // Setting object from HandleInertiaRequests::share() (theme,
            // sidebar_style, etc.); an Inertia::render() prop silently
            // shadows a same-named shared prop, which broke sidebar_style
            // detection (and forced the flat always-expanded sidebar) on
            // this page specifically.
            'attendance_settings' => digital_attendance_settings(),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorize('manage-digital-attendance-settings');

        $data = $request->validate([
            'digital_attendance_enabled' => 'required|boolean',
            'digital_attendance_location_capture' => 'required|boolean',
            'digital_attendance_require_location' => 'required|boolean',
            'digital_attendance_geofencing' => 'required|boolean',
            'digital_attendance_geofence_latitude' => 'nullable|numeric|between:-90,90',
            'digital_attendance_geofence_longitude' => 'nullable|numeric|between:-180,180',
            'digital_attendance_geofence_radius' => 'required|integer|min:10|max:5000',
            'digital_attendance_geofence_mode' => 'required|in:warning,block',
            'digital_attendance_allow_remote' => 'required|boolean',
            'digital_attendance_working_start' => 'required|date_format:H:i',
            'digital_attendance_working_end' => 'required|date_format:H:i',
            'digital_attendance_late_threshold_minutes' => 'required|integer|min:0|max:180',
            'digital_attendance_require_clock_out' => 'required|boolean',
        ]);

        $before = digital_attendance_settings();
        $accountId = getAccountId();

        collect($data)->each(function ($value, $key) use ($accountId) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value === null) {
                $value = '';
            }

            Setting::updateOrCreate(
                ['tec_key' => $key, 'account_id' => $accountId],
                ['tec_value' => $value]
            );
        });

        DigitalAttendanceEvent::record(null, DigitalAttendanceEvent::SETTINGS_CHANGED, __('Digital Attendance settings updated.'), [
            'old_value' => $before,
            'new_value' => $data,
        ]);

        log_activity(__('Digital Attendance settings updated.'), $data, $request->user(), 'DigitalAttendanceSettings');

        $changed = collect($data)->filter(fn ($v, $k) => ($before[$k] ?? null) != $v)->count();
        $me = $request->user();
        \App\Services\Workflow\WorkflowMessenger::dispatch('digital_attendance', 'settings_changed', null, $me, [
            'account_id' => $me->account_id ?? 1, 'title' => __('Attendance Settings Changed'),
            'message' => __('The Digital Attendance settings were changed by :n (:c setting(s) changed).', ['n' => $me->name, 'c' => $changed]),
            'url' => route('digital-attendance-settings.edit'), 'dedupe' => 'digital_attendance|settings|' . intdiv(time(), 20),
        ]);

        return back()->with('message', __('Digital Attendance settings saved.'));
    }
}
