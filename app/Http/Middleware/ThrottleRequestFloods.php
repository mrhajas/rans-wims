<?php

namespace App\Http\Middleware;

use App\Services\SecurityAlert;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Server-side rate limits that apply to every web request (the limits named on single routes stay as they are):
 *   - a ceiling per person (signed in) or per address (guest) for all requests, far above normal use, so a script hammering the server is stopped;
 *   - a lower ceiling for requests that change data;
 *   - the sign-in pages, per address, whatever user names are tried (the login limiter itself counts per user name);
 *   - any request that carries a password (confirming an action with the person's password, changing a password): a few tries per person, so a stolen
 *     session cannot be used to guess the password of the account.
 * Numbers are in config/security.php (rate_limits). A refused request answers 429 with Retry-After; sign-in pages answer with the usual throttle message.
 */
class ThrottleRequestFloods
{
    private const AUTH_PATHS = ['login', 'face-login/challenge', 'face-login/verify', 'forgot-password', 'reset-password', 'two-factor-challenge', 'two-factor-challenge/send'];

    public function handle(Request $request, Closure $next)
    {
        $cfg = config('security.rate_limits');
        if (! $cfg || ! config('security.rate_limit_enabled', true) || ((app()->runningUnitTests() || app()->environment('testing')) && ! config('security.force_in_tests'))) {
            return $next($request);
        }
        $ip = (string) $request->ip();
        $who = optional($request->user())->id ? 'u' . $request->user()->id : 'ip' . $ip;
        $path = trim($request->path(), '/');
        $mutating = ! $request->isMethod('GET') && ! $request->isMethod('HEAD') && ! $request->isMethod('OPTIONS');

        if ($mutating && in_array($path, self::AUTH_PATHS, true)) {
            $this->hit('auth-ip:' . $ip . ':min', $cfg['auth_per_ip_minute'], 60, $request, 'sign-in page', true);
            $this->hit('auth-ip:' . $ip . ':hour', $cfg['auth_per_ip_hour'], 3600, $request, 'sign-in page', true);
        }
        $this->hit('all:' . $who, optional($request->user())->id ? $cfg['per_user_minute'] : $cfg['per_guest_minute'], 60, $request, 'too many requests');
        if ($mutating) {
            $this->hit('write:' . $who, $cfg['writes_per_minute'], 60, $request, 'too many changes');
        }
        if ($mutating && ! in_array($path, self::AUTH_PATHS, true) && $request->user() && $this->carriesPassword($request)) {
            $this->hit('pw:' . $who, $cfg['password_checks_per_10_min'], 600, $request, 'password attempts', true);
        }

        return $next($request);
    }

    private function carriesPassword(Request $request): bool
    {
        foreach (array_keys($request->except(['_token'])) as $k) {
            if (is_string($k) && stripos($k, 'password') !== false) {
                return true;
            }
        }

        return false;
    }

    private function hit(string $key, int $max, int $seconds, Request $request, string $kind, bool $alert = false): void
    {
        $key = 'flood:' . $key;
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $wait = RateLimiter::availableIn($key);
            SecurityAlert::note((string) $request->ip(), 'rate limit: ' . $kind);
            $this->refuse($request, $wait, $kind);
        }
        RateLimiter::hit($key, $seconds);
    }

    private function refuse(Request $request, int $wait, string $kind): void
    {
        $path = trim($request->path(), '/');
        if (in_array($path, self::AUTH_PATHS, true) && ! $request->isMethod('GET')) {
            throw ValidationException::withMessages(['username' => [trans('auth.throttle', ['seconds' => $wait, 'minutes' => ceil($wait / 60)])]]);
        }
        throw new TooManyRequestsHttpException($wait, __('Too many requests. Please wait :s seconds and try again.', ['s' => $wait]));
    }
}
