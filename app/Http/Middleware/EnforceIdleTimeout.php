<?php

namespace App\Http\Middleware;

use App\Support\IdleTimeout;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Signs a person out when their whole login session has been idle for longer than their auto-logout limit. See App\Support\IdleTimeout. */
class EnforceIdleTimeout
{
    private const GRACE_SECONDS = 15; // the browser warns and signs out itself; the server only backs it up, so it waits a little longer

    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('web');
        $user = $guard->user();
        if (! $user || $request->routeIs('logout')) {
            return $next($request);
        }
        $now = time();
        $remembered = $request->hasCookie($guard->getRecallerName());
        $minutes = IdleTimeout::minutesFor($user, $remembered);
        $last = (int) $request->session()->get('last_activity_at', 0);

        if ($minutes > 0 && $last > 0 && ($now - $last) > $minutes * 60 + self::GRACE_SECONDS) {
            return $this->expire($request, $guard, $minutes);
        }

        // what counts as activity: the idle time the browser reports (it knows about every tab); without that, a page load, a form post or a visible
        // Inertia visit. A background request (polling, a partial reload, an XHR GET without the header) does not.
        $reported = $request->header('X-Idle-Ms');
        if ($reported !== null && ctype_digit((string) $reported)) {
            $seen = $now - intdiv((int) $reported, 1000);
            $request->session()->put('last_activity_at', max($last, min($now, $seen)));
        } elseif ($last === 0 || $this->isUserRequest($request)) {
            $request->session()->put('last_activity_at', $now);
        }

        return $next($request);
    }

    private function isUserRequest(Request $request): bool
    {
        if ($request->header('X-Idle-Ignore')) {
            return false;
        }
        if ($request->header('X-Inertia')) {
            return ! $request->header('X-Inertia-Partial-Data') && ! $request->header('X-Inertia-Partial-Component');
        }
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return true;
        }

        // a plain GET counts only when it is a page the browser opened (it asks for HTML); a script's fetch() or a poll does not
        return ! $request->ajax() && ! $request->expectsJson() && str_contains(strtolower((string) $request->header('Accept', '')), 'text/html');
    }

    private function expire(Request $request, $guard, int $minutes)
    {
        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $message = __('You were signed out after :n minutes without activity. Please sign in again.', ['n' => $minutes]);
        $request->session()->flash('status', $message);

        if ($request->header('X-Inertia')) {
            return response('', 409, ['X-Inertia-Location' => route('login')]);
        }
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => $message, 'idle_logout' => true], 401);
        }

        return redirect()->route('login');
    }
}
