<?php

namespace App\Http\Middleware;

use App\Services\Prayer\PrayerService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Prayer Times. While the prayer lock is on, everybody in scope is signed out on their next request and sees why on the sign-in page
 * (the password step in FortifyServiceProvider and FaceLoginController stop new sign-ins). Switched on and tuned in Prayer Times > Settings.
 */
class EnforcePrayerLock
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! ($lock = PrayerService::lockFor($user))) {
            return $next($request);
        }
        if ($request->routeIs('logout')) {
            return $next($request);
        }

        $message = __('WIMS is on prayer break for :p. You can sign in again at :t.', ['p' => $lock['label'], 't' => $lock['unlock_at']->format('h:i A')]);

        if (! $request->hasSession()) {
            abort(423, $message);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('prayer_notice', $message);

        $loginUrl = route('login', ['prayer' => 1]);

        return $request->header('X-Inertia') ? Inertia::location($loginUrl) : redirect()->to($loginUrl);
    }
}
