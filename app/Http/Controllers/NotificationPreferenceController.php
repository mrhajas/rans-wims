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
}
