<?php

namespace App\Http\Middleware;

use App\Models\ImpersonationSession;
use App\Models\User;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Runs on every web request. Three jobs, in order:
 *  1. Self-heal a stale/expired/terminated impersonation (restore the
 *     original Super Admin, log the reason) before anything else sees it.
 *  2. Enforce the session's expiry (#12).
 *  3. Block high-risk / irreversible actions and, in Read-Only mode, every
 *     mutating request, while a live impersonation is active (#23, #25).
 */
class ImpersonationGuard
{
    public function handle(Request $request, Closure $next)
    {
        $sessionId = $request->session()->get('impersonation.session_id');

        if (! $sessionId) {
            return $next($request);
        }

        $record = ImpersonationSession::find($sessionId);

        if (! $record || ! $record->isActive()) {
            $this->restoreAfterInactiveRecord($request, $record);

            return $this->bounce($request, __('Impersonation session expired.'));
        }

        if ($record->isExpired()) {
            Impersonation::end($request, ImpersonationSession::STATUS_EXPIRED);

            return $this->bounce($request, __('Impersonation session expired.'));
        }

        if ($blocked = $this->blockedReason($request, $record)) {
            $this->logSecurityEvent('IMPERSONATION_ACTION_BLOCKED', $record, ['route' => $request->route()?->getName(), 'reason' => $blocked]);

            abort(403, __('This action is unavailable during impersonation.'));
        }

        return $next($request);
    }

    /**
     * The DB row says this impersonation is no longer active (terminated by
     * another Super Admin from Active Impersonations, or otherwise cleared)
     * but THIS browser's session still carries the old keys — restore the
     * original Super Admin from whatever identity we can still resolve.
     */
    protected function restoreAfterInactiveRecord(Request $request, ?ImpersonationSession $record): void
    {
        $originalId = $record?->original_user_id ?? $request->session()->get('impersonation.original_user_id');
        $original = $originalId ? User::find($originalId) : null;

        $request->session()->forget(['impersonation.session_id', 'impersonation.original_user_id']);

        if ($original) {
            Auth::guard('web')->login($original);
            Impersonation::syncGuards($original);
        }
    }

    protected function blockedReason(Request $request, ImpersonationSession $record): ?string
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $routeName = $request->route()?->getName();
        if (! $routeName) {
            return null;
        }

        // Always allow exiting, regardless of mode.
        if ($routeName === 'impersonation.exit') {
            return null;
        }

        if (in_array($routeName, (array) config('impersonation.blocked_digital_attendance_routes', []), true)) {
            return 'digital_attendance';
        }

        foreach ((array) config('impersonation.blocked_route_prefixes', []) as $prefix) {
            if (Str::startsWith($routeName, $prefix)) {
                return 'high_risk_route';
            }
        }

        if ($record->mode === ImpersonationSession::MODE_READ_ONLY) {
            return 'read_only_mode';
        }

        return null;
    }

    protected function logSecurityEvent(string $event, ImpersonationSession $record, array $properties = []): void
    {
        activity('Impersonation')
            ->causedBy($record->originalUser)
            ->withProperties(array_merge($properties, [
                '_impersonation_explicit_causer' => true,
                'impersonation_id' => $record->code,
                'effective_user_id' => $record->target_user_id,
            ]))
            ->log($event);
    }

    protected function bounce(Request $request, string $message)
    {
        if ($request->header('X-Inertia')) {
            return redirect()->route('dashboard')->with('error', $message);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 419);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }
}
