<?php

namespace App\Support;

use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Single choke point for "Login As User". Everything else in the app —
 * middleware, the Activity dual-identity hook, Vue banners — goes through
 * these static accessors instead of touching session keys directly, so the
 * impersonation concept stays centralized (see WIMS-SECURE-USER-IMPERSONATION
 * spec #40).
 *
 * Deliberately reuses the app's normal `web` session/guard rather than a
 * competing auth system: Auth::user() IS the effective user (target) while
 * active, so every existing permission check, warehouse scope, and Inertia
 * share (all_permissions, managedWarehouses, etc.) automatically reflects
 * the target user with no per-feature changes. The original Super Admin's
 * identity is tracked separately via the `impersonation.*` session keys and
 * the impersonation_sessions row, which is what originalUser() reads.
 */
class Impersonation
{
    protected static ?ImpersonationSession $resolved = null;
    protected static bool $resolvedAttempted = false;

    public static function isActive(): bool
    {
        return static::record() !== null;
    }

    public static function record(): ?ImpersonationSession
    {
        if (static::$resolvedAttempted) {
            return static::$resolved;
        }
        static::$resolvedAttempted = true;

        $id = session('impersonation.session_id');
        if (! $id) {
            return static::$resolved = null;
        }

        $record = ImpersonationSession::find($id);
        if (! $record || ! $record->isActive()) {
            return static::$resolved = null;
        }

        return static::$resolved = $record;
    }

    public static function originalUser(): ?User
    {
        return static::record()?->originalUser;
    }

    public static function targetUser(): ?User
    {
        return static::isActive() ? Auth::guard('web')->user() : null;
    }

    public static function id(): ?string
    {
        return static::record()?->code;
    }

    public static function isReadOnly(): bool
    {
        return static::record()?->mode === ImpersonationSession::MODE_READ_ONLY;
    }

    /** Clears the request-local cache — used right after start()/end() mutate state mid-request. */
    protected static function forget(): void
    {
        static::$resolved = null;
        static::$resolvedAttempted = false;
    }

    public static function generateCode(): string
    {
        $date = now()->format('Ymd');
        $seq = ImpersonationSession::whereDate('created_at', now()->toDateString())->count() + 1;

        return sprintf('IMP-%s-%04d', $date, $seq);
    }

    public static function start(User $originalSuperAdmin, User $target, string $reason, string $mode, Request $request): ImpersonationSession
    {
        // Super-Admin-editable via Settings → User Impersonation (password
        // re-verified there too — see SettingController::updateImpersonationExpiry());
        // config('impersonation.default_expiry_minutes') is only the fallback
        // for a fresh install before that Setting row exists.
        $minutes = (int) (get_settings('impersonation_expiry_minutes') ?: config('impersonation.default_expiry_minutes', 30));

        // Logged BEFORE the identity switch, with an explicit causer, so this
        // first entry is unambiguously attributed to the Super Admin even
        // though the dual-identity Activity hook (see Activity::booted())
        // would otherwise also catch it.
        activity('Impersonation')
            ->causedBy($originalSuperAdmin)
            ->performedOn($target)
            ->withProperties(['_impersonation_explicit_causer' => true, 'reason' => $reason, 'mode' => $mode])
            ->log('IMPERSONATION_STARTED');

        $record = ImpersonationSession::create([
            'code' => static::generateCode(),
            'account_id' => $originalSuperAdmin->account_id,
            'original_user_id' => $originalSuperAdmin->id,
            'target_user_id' => $target->id,
            'reason' => $reason,
            'mode' => $mode,
            'status' => ImpersonationSession::STATUS_ACTIVE,
            'started_at' => now(),
            'expires_at' => now()->addMinutes($minutes),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        \App\Models\ImpersonationReason::record($originalSuperAdmin->account_id, $reason);

        // Auth::login() regenerates the session ID (session-fixation
        // protection) — this is the point at which the browser's shared
        // session cookie starts representing the target user. The Super
        // Admin's own identity isn't destroyed: it's preserved below as
        // impersonation.original_user_id and restored verbatim by end().
        Auth::guard('web')->login($target);
        static::syncGuards($target);
        $request->session()->put('impersonation.session_id', $record->id);
        $request->session()->put('impersonation.original_user_id', $originalSuperAdmin->id);

        static::$resolved = $record;
        static::$resolvedAttempted = true;

        return $record;
    }

    public static function end(Request $request, string $status = ImpersonationSession::STATUS_ENDED, ?User $endedBy = null): void
    {
        $record = static::record();
        if (! $record) {
            return;
        }

        $original = $record->originalUser;
        $target = $record->targetUser;
        $eventName = match ($status) {
            ImpersonationSession::STATUS_EXPIRED => 'IMPERSONATION_EXPIRED',
            ImpersonationSession::STATUS_TERMINATED => 'IMPERSONATION_TERMINATED',
            default => 'IMPERSONATION_ENDED',
        };

        activity('Impersonation')
            ->causedBy($endedBy ?? $original)
            ->performedOn($target)
            ->withProperties([
                '_impersonation_explicit_causer' => true,
                'impersonation_id' => $record->code,
                'duration_seconds' => $record->started_at ? $record->started_at->diffInSeconds(now()) : null,
            ])
            ->log($eventName);

        $record->update([
            'status' => $status,
            'ended_at' => now(),
            'ended_by_user_id' => $endedBy?->id ?? $original?->id,
        ]);

        $request->session()->forget(['impersonation.session_id', 'impersonation.original_user_id']);
        static::forget();

        // Restores the Super Admin on THIS session — if this request came
        // from a different tab/admin (terminate from Active Impersonations),
        // the impersonated browser's own next request will find the record
        // no longer active and self-heal via ImpersonationGuard.
        if ($original && Auth::guard('web')->id() === $target?->id) {
            Auth::guard('web')->login($original);
            static::syncGuards($original);
        }
    }

    /**
     * Every route in this app runs behind `auth:sanctum` (see routes/web.php).
     * For same-origin, session-cookie requests, Sanctum's guard is a
     * RequestGuard whose callback (Laravel\Sanctum\Guard::__invoke) just
     * delegates to Auth::guard('web')->user() — but RequestGuard caches
     * whatever it first resolves for the lifetime of the request (see
     * Illuminate\Auth\RequestGuard::user()). Since something upstream
     * (Authenticate middleware) already resolved 'sanctum' as the SUPER
     * ADMIN earlier in this same request, that cache is now stale relative
     * to the 'web' guard we just logged in above, and anything reading the
     * "default" guard for the rest of THIS request — including Jetstream's
     * AuthenticateSession middleware, which re-stores a password hash after
     * we return — would still see the old identity. setUser() here updates
     * that cache directly; the session-key writes are defense in depth so a
     * mismatched password_hash_{driver} can never force a false logout on
     * the very next request either.
     */
    public static function syncGuards(User $user): void
    {
        Auth::guard('sanctum')->setUser($user);

        $request = request();
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        $request->session()->put('password_hash_sanctum', $user->getAuthPassword());
    }

    public static function shareData(): ?array
    {
        $record = static::record();
        if (! $record) {
            return null;
        }

        $target = Auth::guard('web')->user();

        return [
            'active' => true,
            'id' => $record->code,
            'mode' => $record->mode,
            'target' => [
                'id' => $target->id,
                'name' => $target->name,
                'role' => $target->roles->pluck('name')->first(),
            ],
            'original' => [
                'id' => $record->original_user_id,
                'name' => optional($record->originalUser)->name,
            ],
            'reason' => $record->reason,
            'started_at' => optional($record->started_at)->toIso8601String(),
            'expires_at' => optional($record->expires_at)->toIso8601String(),
        ];
    }
}
