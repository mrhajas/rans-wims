<?php

namespace App\Http\Middleware;

use App\Services\FeatureManager;
use Closure;
use Illuminate\Http\Request;

/**
 * Global Feature Control enforcement. Registered once (alias
 * `feature.gate`) on the main authenticated route group — no per-route
 * wiring needed. A route belonging to a currently-disabled function is
 * blocked for EVERYONE, Super Admins included, server-side, regardless of
 * what the frontend chooses to show. Only Feature Control itself (so it can
 * always be switched back on) and the notice page stay reachable. A Super
 * Admin is sent to the same notice page, which tells them the module has
 * been deactivated and offers to switch it back on.
 */
class FeatureGate
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        $routeName = $request->route()?->getName();

        if (! $user || FeatureManager::alwaysReachable($routeName)) {
            return $next($request);
        }

        $featureKey = FeatureManager::featureForRoute($routeName);

        if (! $featureKey || FeatureManager::isEnabled($featureKey)) {
            return $next($request);
        }

        $message = __('This WIMS function is currently disabled by the system administrator.');

        if (! $request->header('X-Inertia') && ($request->wantsJson() || $request->ajax())) {
            return response()->json(['message' => $message, 'feature' => $featureKey], 403);
        }

        // Inertia's client doesn't swap pages for non-2xx XHR responses, so —
        // exactly like the existing SystemLockdown middleware — we redirect
        // to a real route instead of rendering the error page in place.
        // Inertia follows the redirect natively and renders the destination
        // page normally.
        // `from` lets the notice page send a Super Admin straight back to the page they wanted once they switch it on (same-site paths only).
        $from = $request->isMethod('GET') ? $request->getRequestUri() : null;

        return redirect()->route('feature-unavailable', array_filter(['feature' => $featureKey, 'from' => $from]));
    }
}
