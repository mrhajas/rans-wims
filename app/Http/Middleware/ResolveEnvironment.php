<?php

namespace App\Http\Middleware;

use App\Support\Simulation\Environment;
use App\Support\Simulation\EnvironmentCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Decides, ONCE and before the session starts, which WIMS this request belongs to. Production is the default and the answer whenever there is any doubt.
 * Simulation only when the server's own encrypted cookie says so AND the simulation is switched on and its database answers. It never falls back:
 * if the simulation database cannot be reached the request stops with a plain error.
 */
class ResolveEnvironment
{
    public function handle(Request $request, Closure $next)
    {
        $cookie = EnvironmentCookie::read($request);
        $d = Environment::decide($cookie, $request->method(), $request->path(), $request->headers->has('X-Inertia'));
        $env = $d['env'];
        $clear = $d['clear'];
        if (Environment::isSimulation()) {
            $env = Environment::SIMULATION;      // the request started in the simulation; it stays there
            $clear = false;
        }
        if ($env === Environment::SIMULATION) {
            try {
                Environment::activate(Environment::SIMULATION);      // already done when the request started; this only matters when something else started the application
                Environment::assertConnected();
            } catch (\Throwable $e) {
                Log::error('Simulation environment unavailable', ['error' => $e->getMessage()]);
                Environment::reset();

                // the cookie stays: every retry fails the same way until the simulation is back (or the person refreshes the login page); it never becomes a production sign-in
                return response('Service temporarily unavailable.', 503);
            }
        }
        $response = $next($request);
        // a simulation sign-in that no longer holds (signed out, timed out, session gone): the next login page must be the real one, not the simulation's
        if (! $clear && $cookie && empty($cookie['p']) && Environment::isSimulation() && ! \Illuminate\Support\Facades\Auth::check()) {
            $clear = true;
        }
        if ($clear) {
            $response->headers->setCookie(EnvironmentCookie::forget());
        }

        return $response;
    }

    /**
     * A real visit to the login page: the browser opened or refreshed it (a document request). The page's own script moving around (the redirect back after a wrong
     * password, the quiet refresh of its question) travels with the Inertia header and keeps the choice.
     */
    private function freshLoginPage(Request $request): bool
    {
        return $request->isMethod('GET') && trim($request->path(), '/') === 'login' && ! $request->headers->has('X-Inertia');
    }
}
