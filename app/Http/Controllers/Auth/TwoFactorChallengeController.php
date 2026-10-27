<?php

namespace App\Http\Controllers\Auth;

use App\Http\Middleware\SystemLockdown;
use App\Http\Requests\TwoFactorChallengeRequest;
use App\Services\TwoFactorOtp;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Events\RecoveryCodeReplaced;

/**
 * Replaces Fortify's TwoFactorAuthenticatedSessionController (bound in FortifyServiceProvider) so the
 * second factor can be the authenticator app, an Email code, an SMS code or a recovery code — whichever
 * the user picks on the challenge page — and so the Maintenance Lock is re-checked at the moment the
 * code is accepted. Extends Laravel's plain Controller (like Fortify's own), NOT App\Http\Controllers\Controller:
 * that one auto-checks a derived permission on every action, and nobody is signed in yet at this point.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(protected StatefulGuard $guard, protected TwoFactorOtp $otp)
    {
    }

    public function create(TwoFactorChallengeRequest $request)
    {
        if (! $request->hasChallengedUser()) {
            return redirect()->route('login');
        }

        $user = $request->challengedUser();

        if (SystemLockdown::blocks($user)) {
            return $this->lockedOut($request);
        }

        $channels = $this->otp->enabledChannels($user);
        $hasApp = ! empty($user->two_factor_secret);

        $state = $this->otp->state($user);
        // Someone whose only second factor is a single Email/SMS code gets it sent straight away: the page asks for it itself as it opens
        // (sending from here made the page wait for the mail server before showing anything).
        $autoSend = ! $hasApp && count($channels) === 1 && ! $state;

        $methods = [];
        if ($hasApp) {
            $methods[] = ['key' => 'app', 'available' => true, 'masked' => null];
        }
        foreach ($channels as $channel) {
            $methods[] = [
                'key' => $channel,
                'available' => $channel === 'sms' ? $this->otp->smsAvailable() : true,
                'masked' => $this->otp->mask($channel, $this->otp->destination($user, $channel)),
            ];
        }

        $first = collect($methods)->firstWhere('available', true)['key'] ?? ($methods[0]['key'] ?? 'app');

        return Inertia::render('Auth/TwoFactorChallenge', [
            'methods' => $methods,
            // recoveryCodes() decrypts the column, which throws when it is empty (email/SMS-only users have none).
            'hasRecovery' => $hasApp && ! empty($user->two_factor_recovery_codes) && count($user->recoveryCodes()) > 0,
            'initialMethod' => $state['channel'] ?? $first,
            'otp' => $state,
            'otpTtlMinutes' => (int) (TwoFactorOtp::TTL_SECONDS / 60),
            'autoSend' => $autoSend,
        ]);
    }

    /** Sends (or re-sends) an Email/SMS code for the user waiting on the challenge page. */
    public function send(TwoFactorChallengeRequest $request)
    {
        $request->validate(['channel' => 'required|string|in:email,sms']);

        if (! $request->hasChallengedUser()) {
            return redirect()->route('login');
        }

        $user = $request->challengedUser();

        if (SystemLockdown::blocks($user)) {
            return $this->lockedOut($request);
        }

        $channel = $request->input('channel');

        if (! in_array($channel, $this->otp->enabledChannels($user), true)) {
            throw ValidationException::withMessages(['code' => [__('That verification method is not available for your account.')]]);
        }

        $result = $this->otp->send($user, $channel);

        if (! $result['ok']) {
            throw ValidationException::withMessages(['code' => [$result['error']]]);
        }

        return back();
    }

    public function store(TwoFactorChallengeRequest $request)
    {
        $user = $request->challengedUser();

        if (SystemLockdown::blocks($user)) {
            return $this->lockedOut($request);
        }

        $method = $request->input('method') ?: ($request->filled('recovery_code') ? 'recovery' : 'app');
        $field = $method === 'recovery' ? 'recovery_code' : 'code';
        $error = __('The provided two factor authentication code was invalid.');
        $ok = false;

        if ($method === 'recovery') {
            if (! empty($user->two_factor_recovery_codes) && ($code = $request->validRecoveryCode())) {
                $user->replaceRecoveryCode($code);
                event(new RecoveryCodeReplaced($user, $code));
                $ok = true;
            }
        } elseif (in_array($method, TwoFactorOtp::CHANNELS, true)) {
            if (! in_array($method, $this->otp->enabledChannels($user), true)) {
                $error = __('That verification method is not available for your account.');
            } else {
                $result = $this->otp->verify($user, $method, (string) $request->input('code'));
                $ok = $result['ok'];
                $error = $result['error'] ?? $error;
            }
        } else {
            $ok = ! empty($user->two_factor_secret) && $request->hasValidCode();
        }

        if (! $ok) {
            // a wrong second-step code counts as a wrong sign-in for the address
            \App\Services\Security\AccessGuard::recordLoginFailure((string) $request->ip(), 'sign-in code');

            throw ValidationException::withMessages([$field => [$error]]);
        }

        $remember = $request->remember();
        $request->session()->forget('login.id');
        $this->otp->forget($user);

        $this->guard->login($user, $remember);

        $request->session()->regenerate();

        return app(TwoFactorLoginResponse::class);
    }

    protected function lockedOut(TwoFactorChallengeRequest $request)
    {
        $request->session()->forget(['login.id', 'login.remember']);

        $notice = SystemLockdown::notice();

        return redirect()->route('login', ['maintenance' => 1])
            ->with('error', $notice)
            ->with('maintenance_notice', $notice);
    }
}
