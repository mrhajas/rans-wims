<?php

namespace App\Providers;

use App\Models\User;
use App\Http\Middleware\SystemLockdown;
use App\Models\LoginLog;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Fortify;
use App\Actions\Fortify\CreateNewUser;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use Illuminate\Support\Facades\RateLimiter;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

class FortifyServiceProvider extends ServiceProvider
{
    // How many recent failed attempts on one username before the login form
    // starts requiring the arithmetic challenge below.
    private const CHALLENGE_AFTER_FAILURES = 3;

    private const CHALLENGE_WINDOW_MINUTES = 15;

    public function boot()
    {
        // Fortify's own logout() call invalidates the session (regenerates
        // + flushes it) *before* redirecting, which would wipe out a normal
        // session-flash flag set from a Logout event listener. A query
        // param on the redirect survives that, so the login page's
        // gate-closing animation (GateReveal.vue, mirroring the one that
        // plays on login) can key off it safely.
        $this->app->singleton(LogoutResponse::class, function () {
            return new class implements LogoutResponse
            {
                public function toResponse($request)
                {
                    return redirect()->route('login', ['logged_out' => 1]);
                }
            };
        });

        // Second-factor challenge: authenticator app, Email code, SMS code or recovery code (user's choice),
        // with the Maintenance Lock re-checked when the code is accepted. Fortify's routes are untouched —
        // they just resolve this controller instead of the vendor one.
        $this->app->bind(
            \Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController::class,
            \App\Http\Controllers\Auth\TwoFactorChallengeController::class
        );

        // Authenticator app and Email/SMS codes are mutually exclusive (see the action's docblock).
        $this->app->bind(
            \Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class,
            \App\Actions\Fortify\EnableTwoFactorAuthenticationExclusive::class
        );

        // Same login pipeline as Fortify's default, except users whose only second factor is an Email/SMS
        // code are challenged too (the stock step only looks for an authenticator-app secret).
        Fortify::authenticateThrough(function (Request $request) {
            return array_filter([
                config('fortify.limiters.login') ? null : \Laravel\Fortify\Actions\EnsureLoginIsNotThrottled::class,
                config('fortify.lowercase_usernames') ? \Laravel\Fortify\Actions\CanonicalizeUsername::class : null,
                \App\Actions\Fortify\RedirectIfTwoFactorOrOtp::class,
                \Laravel\Fortify\Actions\AttemptToAuthenticate::class,
                \Laravel\Fortify\Actions\PrepareAuthenticatedSession::class,
            ]);
        });

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Overrides Jetstream's default login view (see config/app.php for why
        // this provider must boot after JetstreamServiceProvider for this to
        // actually take effect) purely to hand the page a fresh signed
        // challenge — same page/component, no other behavior changes.
        Fortify::loginView(function (Request $request) {
            return Inertia::render('Auth/Login', [
                'canResetPassword' => Route::has('password.request'),
                'status' => session('status'),
                'challenge' => $this->generateLoginChallenge(),
                'justLoggedOut' => $request->boolean('logged_out'),
                'prayerNotice' => \App\Services\Prayer\PrayerService::loginNotice(),
                'faceLoginEnabled' => \App\Services\FeatureManager::isEnabled('face_login') && \Illuminate\Support\Facades\Schema::hasTable('face_templates'),
            ]);
        });

        Fortify::authenticateUsing(function (Request $request) {
            // Honeypot: a field real users never see or fill (styled
            // off-screen in Auth/Login.vue). Any value here means an
            // automated form-filler touched it — reject with the same
            // generic outcome a wrong password gets, so it can't tell it was
            // caught rather than just wrong.
            if (filled($request->input('hp_check'))) {
                return null;
            }

            $username = Str::lower((string) $request->input(Fortify::username()));

            // After a few recent failures on this specific username, require
            // the signed arithmetic challenge to be answered correctly
            // before even checking credentials. This is deliberately not a
            // real CAPTCHA (no third-party service) — it's friction against
            // naive scripts that only know how to POST username+password;
            // see FortifyServiceProvider@verifyLoginChallenge for the
            // tamper-proofing (the numbers are bound into a signed token, not
            // trusted from raw hidden inputs).
            //
            // Auth/Login.vue reveals the challenge field client-side after
            // just 1 failed attempt (localStorage-tracked), well before this
            // server-side threshold of 3 is reached — so a submission can
            // arrive with a filled-in (and possibly wrong) answer even when
            // `$forceRequired` below is still false. Verifying it whenever
            // it was actually submitted (not only when the server would have
            // forced it) closes that gap: once the box is on screen, a wrong
            // answer must never be able to ride through on a correct
            // password.
            $forceRequired = $username !== '' && $this->recentFailureCount($username) >= self::CHALLENGE_AFTER_FAILURES;
            // Auth/Login.vue's submit() always sends `challenge_token` (it's
            // read off the page-load prop unconditionally, whether or not
            // the box is on screen) — only `challenge_answer` reflects
            // whether the user actually saw and filled in the field, so that
            // alone is what "was a challenge attempted" has to key off.
            $challengeSubmitted = filled($request->input('challenge_answer'));

            if ($forceRequired || $challengeSubmitted) {
                if (! $this->verifyLoginChallenge($request)) {
                    throw ValidationException::withMessages([
                        Fortify::username() => [__('Please answer the verification question below to continue.')],
                    ]);
                }
            }

            $user = User::query()->where('username', $request->input(Fortify::username()))->first();
            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            // Simulation WIMS and production never share people: only a Category Authority account may sign in to the simulation, and such an account never signs in to production
            if (\App\Support\Simulation\Environment::isSimulation() !== \App\Services\Simulation\SimulationAccounts::isCategoryAuthority($user)) {
                return null;
            }

            if ($user->is_active === false) {
                $ua = (string) $request->userAgent();
                $agent = user_agent_info($ua);

                LoginLog::query()->create([
                    'account_id' => $user->account_id ?: getAccountId(1),
                    'user_id' => $user->id,
                    'username' => $user->username ?: $user->email,
                    'role' => optional($user->roles->first())->name,
                    'status' => 'failed',
                    'ip_address' => $request->ip(),
                    'device' => $agent['device'],
                    'os' => $agent['os'],
                    'browser' => $agent['browser'],
                    'location' => geo_from_ip($request->ip()),
                    'user_agent' => $ua,
                    'error_message' => 'Account disabled - login blocked',
                    'attempted_at' => now(),
                ]);

                throw ValidationException::withMessages([
                    Fortify::username() => [
                        __('Your account has been put on hold. Please contact your administrator.'),
                    ],
                ]);
            }

            if (SystemLockdown::blocks($user)) {
                throw ValidationException::withMessages([
                    Fortify::username() => [
                        __('System is currently under maintenance. Access is restricted except for Admin, Super Admin, and Project Manager.'),
                    ],
                ]);
            }

            // WIMS Licence: an expired, deactivated or missing licence stops everybody except the Licence Manager.
            if (! \App\Services\Licensing\LicenceService::usable() && ! \App\Services\Licensing\LicenceService::isManager($user)) {
                throw ValidationException::withMessages([
                    Fortify::username() => [\App\Services\Licensing\LicenceService::blockMessage()],
                ]);
            }

            // Prayer Times: while the prayer lock is on, people in scope cannot sign in.
            if ($lock = \App\Services\Prayer\PrayerService::lockFor($user)) {
                throw ValidationException::withMessages([
                    Fortify::username() => [__('Prayer break: WIMS is paused for :p prayer. You can sign in again at :t.', ['p' => $lock['label'], 't' => $lock['unlock_at']->format('h:i A')])],
                ]);
            }

            return $user;
        });

        RateLimiter::for('login', function (Request $request) {
            $username = Str::lower((string) $request->input(Fortify::username()));

            $throttleResponse = function (Request $request, array $headers) {
                $seconds = $headers['Retry-After'] ?? 60;

                throw ValidationException::withMessages([
                    Fortify::username() => [trans('auth.throttle', ['seconds' => $seconds])],
                ]);
            };

            $perIpAndUsername = Limit::perMinute(10)
                ->by($username . '|' . $request->ip())
                ->response($throttleResponse);

            // Closes an IP-rotation gap in the limiter above: that one resets
            // per source IP, so a distributed attacker gets a fresh 10/min
            // budget from every proxy against the same target account. This
            // second limit caps total attempts against one username across
            // *all* IPs combined, independent of where they come from.
            $perUsernameAcrossAllIps = Limit::perMinutes(10, 20)
                ->by('username-only|' . $username)
                ->response($throttleResponse);

            return [$perIpAndUsername, $perUsernameAcrossAllIps];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Fortify's password.email/password.update routes (vendor:
        // laravel/fortify/routes/routes.php) carry no throttle middleware
        // and no config('fortify.limiters.*') hook exists for them, unlike
        // login/two-factor/verification — so an attacker could otherwise
        // submit unlimited forgot-password requests across many different
        // emails from one IP (the 60s per-email cooldown in config/auth.php
        // only limits repeats against the *same* email). Attached once every
        // provider has booted so these vendor-registered routes already
        // exist to attach to.
        RateLimiter::for('reset-password', function (Request $request) {
            return Limit::perMinute(6)->by($request->ip());
        });

        $this->app->booted(function () {
            foreach (['password.email', 'password.update'] as $name) {
                optional(Route::getRoutes()->getByName($name))->middleware('throttle:reset-password');
            }
        });
    }

    public function register()
    {
        //
    }

    /**
     * Two small numbers plus a signed token binding them (and an expiry)
     * together via APP_KEY — Auth/Login.vue renders "What is {a} + {b}?" and
     * echoes the token back on submit. Signing (not just posting raw a/b
     * fields) is what stops a submission from claiming different numbers
     * than the ones actually shown, i.e. it stops tampering; it does not
     * stop a bot that bothers to read the page and compute the sum, which is
     * the intended, accepted limit of a no-third-party-service approach.
     */
    private function generateLoginChallenge(): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $expires = now()->addMinutes(self::CHALLENGE_WINDOW_MINUTES)->timestamp;

        return [
            'a' => $a,
            'b' => $b,
            'token' => Crypt::encryptString("{$a}|{$b}|{$expires}"),
        ];
    }

    private function verifyLoginChallenge(Request $request): bool
    {
        $token = (string) $request->input('challenge_token');
        $answer = $request->input('challenge_answer');

        if ($token === '' || $answer === null || $answer === '') {
            return false;
        }

        try {
            [$a, $b, $expires] = explode('|', Crypt::decryptString($token));
        } catch (\Throwable $e) {
            return false;
        }

        if ((int) $expires < now()->timestamp) {
            return false;
        }

        return (int) $answer === ((int) $a + (int) $b);
    }

    /**
     * Backed by LoginLog (already comprehensive — every attempt, success or
     * failure, is recorded there) rather than a new cache counter, so this
     * can't drift out of sync with what the Login Logs admin page shows.
     */
    private function recentFailureCount(string $username): int
    {
        return LoginLog::query()
            ->where('username', $username)
            ->where('status', 'failed')
            ->where('attempted_at', '>=', now()->subMinutes(self::CHALLENGE_WINDOW_MINUTES))
            ->count();
    }
}
