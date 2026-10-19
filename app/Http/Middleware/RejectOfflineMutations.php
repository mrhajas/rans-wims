<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Defense-in-depth, not the primary offline-blocking mechanism — a
 * genuinely offline browser simply has no server to send a mutation to.
 * This only matters for a stale/reconnected tab whose JS still believes it
 * is offline (see resources/js/Core/offlineStatus.js) and sends a mutating
 * request anyway with that belief attached via the X-Wims-Offline header.
 * WIMS does not queue offline mutations for later replay, so this header is
 * always set fresh from live client state, never from anything persisted.
 * Registered once (alias `reject.offline.mutations`) on the main
 * authenticated route group, mirroring FeatureGate's structure.
 */
class RejectOfflineMutations
{
    public function handle(Request $request, Closure $next)
    {
        $isMutating = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $declaresOffline = $request->header('X-Wims-Offline') === '1';

        if (! $isMutating || ! $declaresOffline) {
            return $next($request);
        }

        $message = __('This action cannot be performed while WIMS is offline. Please reconnect and try again.');

        if (! $request->header('X-Inertia') && ($request->wantsJson() || $request->ajax())) {
            return response()->json(['message' => $message], 409);
        }

        return back()->with('error', $message);
    }
}
