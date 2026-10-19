<?php

namespace App\Http\Middleware;

use App\Services\Security\DeviceLimit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Holds a new sign-in on the "Too many devices" page when the person is already signed in on the allowed number of devices (Settings > Devices per user).
 * Every login method (password, two-factor, face) ends in the same session, so the rule is checked here, once, whatever way the person signed in.
 * A held session can only reach that page and log out; everything else is answered with a redirect (or 423 for a background request).
 */
class EnforceDeviceLimit
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('web')->user();
        if (! $user || ! DeviceLimit::enabled() || $request->session()->has('impersonation.session_id')) {
            return $next($request);
        }
        $state = $request->session()->get('device_limit_state');
        $state = match (true) {
            $state === null || $state === 'pending' => DeviceLimit::admit($request, $user),
            default => DeviceLimit::touch($request, $user),
        };
        if ($state !== 'pending' || $request->routeIs('device-limit.*', 'logout')) {
            return $next($request);
        }
        $url = route('device-limit.show');
        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => __('You are signed in on too many devices. Sign one out to continue.'), 'device_limit' => true, 'url' => $url], 423);
        }

        return redirect($url);
    }
}
