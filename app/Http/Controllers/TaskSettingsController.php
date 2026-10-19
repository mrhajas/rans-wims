<?php

namespace App\Http\Controllers;

use App\Models\TaskNotificationRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

/**
 * Task Manager settings. Who gets notified, on which channels and with which words is now set on the Task Manager workflow page
 * (route task-manager.workflow); what is left here is the timing of the due reminder.
 */
class TaskSettingsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('manageSettings', \App\Models\Task::class);

        $accountId = $request->user()->account_id;

        return Inertia::render('TaskManager/Settings', [
            'reminderOffsetHours' => (int) (TaskNotificationRule::ruleFor($accountId, TaskNotificationRule::EVENT_DUE_REMINDER)['reminder_offset_hours'] ?? 0),
            'canEdit' => $request->user()->hasRole('Super Admin'),
        ]);
    }

    /** Saves how many hours before the due time a task's reminder goes out (0 = on the due date and time). */
    public function updateNotifications(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasRole('Super Admin'), 403);

        $data = $request->validate([
            'password' => ['required', 'string'],
            'reminder_offset_hours' => ['required', 'integer', 'in:' . implode(',', TaskNotificationRule::REMINDER_OFFSET_HOURS)],
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json(['errors' => ['password' => [__('The password you entered is incorrect.')]]], 422);
        }

        $defaults = TaskNotificationRule::defaults()[TaskNotificationRule::EVENT_DUE_REMINDER];

        $row = TaskNotificationRule::firstOrNew(['account_id' => $user->account_id, 'event_key' => TaskNotificationRule::EVENT_DUE_REMINDER]);
        if (! $row->exists) {
            $row->fill(array_diff_key($defaults, ['reminder_offset_hours' => 1]));
        }
        $row->reminder_offset_hours = (int) $data['reminder_offset_hours'];
        $row->save();

        activity('Task Manager')->withProperties(['reminder_offset_hours' => $data['reminder_offset_hours']])->log(__('Task Manager due reminder timing updated.'));

        \App\Services\TaskWorkflowNotifier::settings('The task reminder timing was set to ' . ((int) $data['reminder_offset_hours'] === 0 ? 'the due date and time' : (int) $data['reminder_offset_hours'] . ' hour(s) before the due time') . ' by ' . $user->name . '.', $user);

        return response()->json(['success' => true]);
    }
}
