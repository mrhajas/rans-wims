<?php

namespace App\Http\Controllers;

use App\Http\Resources\ImpersonationSessionCollection;
use App\Models\ImpersonationReason;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class ImpersonationController extends Controller
{
    public function __construct()
    {
        // Real, grantable permissions (start-impersonation / read-impersonation
        // / manage-impersonation — see PermissionRegistry) rather than the
        // legacy auto-derived gate, so every action below uses can() instead.
        // Super Admin keeps unconditional access via the app's Gate::before
        // bypass regardless of what's granted here.
        $this->setAbilities([]);
    }

    /** Reasons dropdown: this account's previously-used reasons (most recent first), merged with the standing defaults. */
    public function reasons(Request $request)
    {
        abort_unless($request->user()->can('start-impersonation'), 403);

        $custom = ImpersonationReason::where('account_id', $request->user()->account_id)
            ->orderByDesc('last_used_at')
            ->limit(25)
            ->pluck('text');

        $reasons = $custom->concat(config('impersonation.default_reasons', []))
            ->unique()
            ->values();

        return response()->json(['reasons' => $reasons]);
    }

    /**
     * Start impersonating $user. Re-authenticates the Super Admin's OWN
     * password inline on this same request — same pattern already used by
     * UserController::disableAccount()/enableAccount() (a plain Hash::check
     * against the acting user's own password) rather than Fortify's
     * separate password.confirm round trip, so there's exactly one modal
     * and exactly one "Verify & Continue" action. Never the target user's
     * password.
     */
    public function store(Request $request, User $user)
    {
        $actor = $request->user();

        if (! $actor->can('start-impersonation')) {
            $this->logSecurityEvent('UNAUTHORIZED_IMPERSONATION_ATTEMPT', $actor, $user);
            abort(403);
        }

        if ($ineligible = $this->ineligibilityReason($actor, $user)) {
            $this->logDenied($actor, $user, $ineligible);

            return response()->json(['message' => $ineligible], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:191'],
            'mode' => ['nullable', 'in:full,read_only'],
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $actor->password)) {
            $this->logSecurityEvent('IMPERSONATION_PASSWORD_MISMATCH', $actor, $user);

            return response()->json(['errors' => ['password' => [__('The password you entered is incorrect.')]]], 422);
        }

        $record = Impersonation::start($actor, $user, $data['reason'], $data['mode'] ?? ImpersonationSession::MODE_FULL, $request);
        \App\Support\UserAudit::record($user, 'impersonated', ['reason' => $data['reason'], 'mode' => $data['mode'] ?? 'full'], $actor->id);
        \App\Services\UserNotifier::fire('impersonation_started', $user, __('Signed In As Another User'), $actor->name . ' started working inside the account of ' . $user->name . ' (' . $user->username . '). Mode: ' . (($data['mode'] ?? 'full') === 'read_only' ? 'view only' : 'full') . '. Reason: ' . $data['reason'], $actor);

        return response()->json([
            'redirect_url' => route('dashboard'),
            'impersonation_id' => $record->code,
        ]);
    }

    /** Exit Impersonation — callable from the banner on any page, in any tab. */
    public function destroy(Request $request)
    {
        abort_unless(Impersonation::isActive(), 404);

        Impersonation::end($request, ImpersonationSession::STATUS_ENDED);

        return redirect()->route('dashboard')->with('message', __('Impersonation ended. You are back in your own session.'));
    }

    /** Currently active sessions across the account — anyone with read-impersonation can see them; ending a stuck one needs manage-impersonation (see terminate() below). */
    public function activeIndex(Request $request)
    {
        abort_unless($request->user()->can('read-impersonation'), 403);

        $sessions = ImpersonationSession::with(['originalUser:id,name', 'targetUser:id,name'])
            ->where('account_id', $request->user()->account_id)
            ->where('status', ImpersonationSession::STATUS_ACTIVE)
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (ImpersonationSession $s) => $this->presentSession($s));

        return Inertia::render('Administration/ImpersonationActive', [
            'sessions' => $sessions,
        ]);
    }

    /** Force-end someone else's (or your own) stuck impersonation session. */
    public function terminate(Request $request, ImpersonationSession $impersonationSession)
    {
        abort_unless($request->user()->can('manage-impersonation'), 403);
        abort_unless($impersonationSession->account_id === $request->user()->account_id, 404);

        if (! $impersonationSession->isActive()) {
            return back()->with('error', __('That impersonation session has already ended.'));
        }

        $wasSelf = Auth::guard('web')->id() === $impersonationSession->target_user_id
            && session('impersonation.session_id') === $impersonationSession->id;

        if ($wasSelf) {
            Impersonation::end($request, ImpersonationSession::STATUS_TERMINATED, $request->user());

            return redirect()->route('dashboard')->with('message', __('Impersonation session ended.'));
        }

        // Ending someone else's live session: flip the DB row now — the
        // impersonated browser self-heals via ImpersonationGuard on its next
        // request (no realtime push channel in this app to force it sooner).
        $original = $impersonationSession->originalUser;

        activity('Impersonation')
            ->causedBy($request->user())
            ->performedOn($impersonationSession->targetUser)
            ->withProperties([
                '_impersonation_explicit_causer' => true,
                'impersonation_id' => $impersonationSession->code,
                'duration_seconds' => optional($impersonationSession->started_at)->diffInSeconds(now()),
            ])
            ->log('IMPERSONATION_TERMINATED');

        $impersonationSession->update([
            'status' => ImpersonationSession::STATUS_TERMINATED,
            'ended_at' => now(),
            'ended_by_user_id' => $request->user()->id,
        ]);

        return back()->with('message', __(':name\'s impersonation session as :target has been ended.', [
            'name' => optional($original)->name,
            'target' => optional($impersonationSession->targetUser)->name,
        ]));
    }

    public function historyIndex(Request $request)
    {
        abort_unless($request->user()->can('read-impersonation'), 403);

        $filters = $request->only(['original_user_id', 'target_user_id', 'status', 'reason', 'date_from', 'date_to']);
        $accountId = $request->user()->account_id;

        $sessions = ImpersonationSession::with(['originalUser:id,name', 'targetUser:id,name', 'endedBy:id,name'])
            ->where('account_id', $accountId)
            ->filter($filters)
            ->orderByDesc('started_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ImpersonationSession $s) => $this->presentSession($s) + [
                'ended_at' => optional($s->ended_at)->toIso8601String(),
                'ended_by' => $s->endedBy ? ['id' => $s->ended_by_user_id, 'name' => $s->endedBy->name] : null,
            ]);

        $base = ImpersonationSession::where('account_id', $accountId);

        return Inertia::render('Administration/ImpersonationHistory', [
            'filters' => $filters,
            'sessions' => new ImpersonationSessionCollection($sessions),
            // Scoped to who/what actually appears in history, not every
            // Super Admin or possible reason — an empty-result filter option
            // isn't useful.
            'admins' => User::whereIn('id', (clone $base)->distinct()->pluck('original_user_id'))->orderBy('name')->get(['id', 'name']),
            'targets' => User::whereIn('id', (clone $base)->distinct()->pluck('target_user_id'))->orderBy('name')->get(['id', 'name']),
            'reasons' => (clone $base)->distinct()->orderBy('reason')->pluck('reason'),
            'statuses' => [
                ImpersonationSession::STATUS_ACTIVE,
                ImpersonationSession::STATUS_ENDED,
                ImpersonationSession::STATUS_EXPIRED,
                ImpersonationSession::STATUS_TERMINATED,
            ],
        ]);
    }

    public function print(Request $request)
    {
        abort_unless($request->user()->can('read-impersonation'), 403);

        $data = $this->exportPayload($request);
        log_activity('Impersonation History Printed', ['count' => count($data['sessions'])], $request->user(), 'Impersonation');

        return view('reports.impersonation-history', $data + ['autoPrint' => true]);
    }

    public function download(Request $request)
    {
        abort_unless($request->user()->can('read-impersonation'), 403);

        $data = $this->exportPayload($request);
        log_activity('Impersonation History Downloaded', ['count' => count($data['sessions'])], $request->user(), 'Impersonation');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.impersonation-history', $data)
            ->setPaper('a4', 'landscape')
            ->download('impersonation-history-'.now()->format('Ymd-His').'.pdf');
    }

    protected function exportPayload(Request $request): array
    {
        $filters = $request->only(['original_user_id', 'target_user_id', 'status', 'reason', 'date_from', 'date_to']);

        $sessions = ImpersonationSession::with(['originalUser:id,name', 'targetUser:id,name'])
            ->where('account_id', $request->user()->account_id)
            ->filter($filters)
            ->orderByDesc('started_at')
            ->limit(1000)
            ->get()
            ->map(fn (ImpersonationSession $s) => [
                'code' => $s->code,
                'original' => optional($s->originalUser)->name,
                'target' => optional($s->targetUser)->name,
                'reason' => $s->reason,
                'mode' => $s->mode === ImpersonationSession::MODE_READ_ONLY ? __('Read-Only') : __('Full'),
                'status' => ucfirst($s->status),
                'started_at' => optional($s->started_at)->format('Y-m-d H:i'),
                'duration' => $this->durationLabel($s),
            ])
            ->values()
            ->all();

        return [
            'title' => 'WIMS - Impersonation History',
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'sessions' => $sessions,
        ];
    }

    protected function durationLabel(ImpersonationSession $s): string
    {
        if (! $s->started_at) {
            return '-';
        }
        $minutes = max(0, $s->started_at->diffInMinutes($s->ended_at ?: now()));

        return $minutes < 1 ? '<1 min' : $minutes.' min';
    }

    protected function ineligibilityReason(User $actor, User $target): ?string
    {
        if ($target->id === $actor->id) {
            return __('You cannot impersonate yourself.');
        }

        if (! $target->is_active || $target->disabled_at) {
            return __('This account is disabled and cannot be impersonated.');
        }

        if ($target->trashed()) {
            return __('This account has been deleted and cannot be impersonated.');
        }

        // A Super Admin account can be entered only by Dr Hajas, really signed in as himself (not through a "Login as user" session).
        if ($target->hasRole('Super Admin') && ! \App\Services\Licensing\LicenceService::isManager($actor)) {
            return __('Only Dr Hajas can sign in as a Super Admin.');
        }

        return null;
    }

    protected function logDenied(User $actor, User $target, string $reason): void
    {
        activity('Impersonation')
            ->causedBy($actor)
            ->performedOn($target)
            ->withProperties(['_impersonation_explicit_causer' => true, 'reason' => $reason])
            ->log('IMPERSONATION_DENIED');
    }

    /** Security-relevant events (#33) — logged even though the actor never gets far enough to hold an impersonation record. */
    protected function logSecurityEvent(string $event, User $actor, ?User $target = null): void
    {
        activity('Impersonation')
            ->causedBy($actor)
            ->performedOn($target ?? $actor)
            ->withProperties(['_impersonation_explicit_causer' => true])
            ->log($event);
    }

    protected function presentSession(ImpersonationSession $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'original' => ['id' => $s->original_user_id, 'name' => optional($s->originalUser)->name],
            'target' => ['id' => $s->target_user_id, 'name' => optional($s->targetUser)->name],
            'reason' => $s->reason,
            'mode' => $s->mode,
            'status' => $s->status,
            'started_at' => optional($s->started_at)->toIso8601String(),
            'expires_at' => optional($s->expires_at)->toIso8601String(),
        ];
    }
}
