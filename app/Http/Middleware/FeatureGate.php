<?php

namespace App\Http\Middleware;

use App\Services\FeatureManager;
use Closure;
use Illuminate\Http\Request;

/**
 * Global Feature Control enforcement. Registered once (alias
 * `feature.gate`) on the main authenticated route group — no per-route
 * wiring needed. Super Admins always pass. Everyone else is blocked from
 * any route belonging to a currently-disabled feature, server-side,
 * regardless of what the frontend chooses to show.
 */
class FeatureGate
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || $user->hasRole('Super Admin')) {
            return $next($request);
        }

        $featureKey = FeatureManager::featureForRoute($request->route()?->getName());

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
        return redirect()->route('feature-unavailable', ['feature' => $featureKey]);
    }
}
