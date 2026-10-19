<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Maintenance Lock. While `system_lockdown` is on, only the roles in ALLOWED_ROLES may be signed in.
 *
 * It is enforced at three points so no sign-in path can slip past it:
 *   1. the password step (FortifyServiceProvider::authenticateUsing) — before 2FA is even offered,
 *   2. the 2FA code step (Auth\TwoFactorChallengeController) — a code entered after the lock went on,
 *   3. this middleware, registered for EVERY web request (see Http\Kernel) — signs out anyone already inside.
 */
class SystemLockdown
{
    public const ALLOWED_ROLES = ['Super Admin', 'Admin', 'Project Manager', 'Project Managers'];

    public static function enabled(): bool
    {
        return filter_var(get_settings('system_lockdown'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function allows(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(self::ALLOWED_ROLES);
    }

    /** True when the lock is on and this user is not one of the allowed roles. */
    public static function blocks(?User $user): bool
    {
        return self::enabled() && ! self::allows($user);
    }

    public static function notice(): string
    {
        return __('System is currently under maintenance. Access is temporarily limited to Admin, Super Admin, and Project Manager. Please try again later.');
    }

    public function handle(Request $request, Closure $next)
    {
        if (! self::enabled()) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user || self::allows($user)) {
            return $next($request);
        }

        // An admin who started an impersonation is the real actor here; only admins can start one.
        if ($request->hasSession() && $request->session()->has('impersonation.session_id')) {
            return $next($request);
        }

        $notice = self::notice();

        // A token-authenticated API call has no session to end — just refuse it.
        if (! $request->hasSession()) {
            abort(503, $notice);
        }

        // Always end the SESSION (web) guard explicitly. `Auth::logout()` goes to the default guard, which
        // behind `auth:sanctum` is a RequestGuard that has no logout() — that threw a 500 and left the user
        // signed in, so every route outside the lockdown group stayed reachable.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $loginUrl = route('login', ['maintenance' => 1]);

        // Keep a fallback flag in URL and flash session so the login page can always show the notice.
        $request->session()->flash('error', $notice);
        $request->session()->flash('maintenance_notice', $notice);

        if ($request->header('X-Inertia')) {
            return Inertia::location($loginUrl);
        }

        return redirect()->to($loginUrl);
    }
}
