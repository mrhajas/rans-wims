<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    // Deliberately not named `update` — Authorizable::callAction would map that
    // to an `update-notification-preferences` permission that doesn't exist
    // for any non-Super-Admin role, silently 403ing everyone else out of
    // saving their own personal preference. Matches the same escape hatch
    // TaskReminderController::updatePreferences() uses.
    public function updatePreferences(Request $request)
    {
        $data = $request->validate(['chime_enabled' => 'required|boolean']);

        $user = $request->user();
        $user->update(['chime_enabled' => $data['chime_enabled']]);

        return response()->json(['chime_enabled' => (bool) $user->fresh()->chime_enabled]);
    }

    // the pop-up toasts for new alerts (Profile > Toast Alerts): on or off, how many seconds each stays, and how many show at one time.
    // Kept in the user's extra_attributes, so there is nothing to migrate. Not named `update...` for the same reason as above.
    public function saveToastSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'seconds' => 'required|integer|min:2|max:30',
            'max' => 'required|integer|min:1|max:5',
        ]);

        $user = $request->user();
        $extra = $user->extra_attributes ?? [];
        $extra['toast_alerts'] = ['enabled' => (bool) $data['enabled'], 'seconds' => (int) $data['seconds'], 'max' => (int) $data['max']];
        $user->extra_attributes = $extra;
        $user->save();

        return response()->json($extra['toast_alerts']);
    }
}
