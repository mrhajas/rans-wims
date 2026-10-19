<?php

namespace App\Http\Middleware;

use App\Support\Simulation\Environment;
use App\Support\Simulation\EnvironmentCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Runs right after the session starts. A signed-in session belongs to exactly ONE environment (marked when it signed in) and may only be used there;
 * in the simulation a person whose account was switched off loses the session on their next request; a simulation cookie with no session behind it
 * (expired, logged out elsewhere) is dropped so the person lands on the normal login page.
 */
class EnsureEnvironmentIntegrity
{
    public function handle(Request $request, Closure $next)
    {
        $sim = Environment::isSimulation();
        $cookie = EnvironmentCookie::read($request);
        if (Auth::check()) {
            $marker = Environment::normalise((string) $request->session()->get('wims.env'));
            $user = Auth::user();
            $dead = $marker !== Environment::current() || ($sim && ($user->is_active === false || $user->is_active === 0))
                || $sim !== \App\Services\Simulation\SimulationAccounts::isCategoryAuthority($user);   // only Category Authority accounts live in the simulation, and none in production
            if ($dead) {
                Log::warning('Cross-environment or deactivated session refused', ['environment' => Environment::current(), 'marker' => $marker, 'user' => $user->getAuthIdentifier(), 'path' => $request->path()]);
                if (function_exists('log_activity')) {
                    try {
                        log_activity('Session refused: wrong environment or account switched off', ['environment' => Environment::current(), 'session' => $marker, 'path' => $request->path()], null, 'Simulation');
                    } catch (\Throwable) {
                    }
                }
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/login')->withCookie(EnvironmentCookie::forget());
            }
        } elseif ($sim && $cookie && ! $cookie['p']) {
            // signed-in cookie but no signed-in session any more: forget it and ask again, as an ordinary visitor
            $to = $request->isMethod('GET') && ! $request->expectsJson() ? $request->fullUrl() : url('/login');

            return redirect($to)->withCookie(EnvironmentCookie::forget());
        }

        return $next($request);
    }
}
