<?php

namespace App\Http\Controllers;

use App\Support\IdleTimeout;
use Illuminate\Http\Request;

/** The person's own auto-logout limit (profile page) and the keep-alive the browser sends while the person is really using WIMS. */
class IdleTimeoutController extends Controller
{
    /** minutes: one of the offered values, 0 for "do not log me out", or "default" for the standard limit. */
    // Deliberately not named `update`: Authorizable would ask for an update-idle-timeout permission nobody has (see NotificationPreferenceController).
    public function savePreference(Request $request)
    {
        $data = $request->validate(['minutes' => ['required', function ($attr, $value, $fail) {
            if ($value !== 'default' && ! (ctype_digit((string) $value) && ((int) $value === 0 || in_array((int) $value, IdleTimeout::OPTIONS, true)))) {
                $fail(__('Choose one of the offered times.'));
            }
        }]]);
        $user = $request->user();
        $user->forceFill(['idle_timeout_minutes' => $data['minutes'] === 'default' ? null : (int) $data['minutes']])->save();
        log_activity('Auto logout time changed', ['minutes' => $data['minutes']], $user, 'Account');

        return response()->json(['minutes' => IdleTimeout::minutesFor($user->fresh(), $request->hasCookie(\Illuminate\Support\Facades\Auth::guard('web')->getRecallerName())), 'chosen' => $user->fresh()->idle_timeout_minutes]);
    }

    /** The browser reports its idle time in X-Idle-Ms; EnforceIdleTimeout has already recorded it. The reply tells the browser how long the session may still stay idle. */
    public function ping(Request $request)
    {
        $minutes = IdleTimeout::minutesFor($request->user(), $request->hasCookie(\Illuminate\Support\Facades\Auth::guard('web')->getRecallerName()));
        $last = (int) $request->session()->get('last_activity_at', time());

        return response()->json(['minutes' => $minutes, 'seconds_left' => $minutes > 0 ? max(0, $minutes * 60 - (time() - $last)) : null]);
    }
}
