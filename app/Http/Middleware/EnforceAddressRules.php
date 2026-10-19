<?php

namespace App\Http\Middleware;

use App\Services\Security\AccessGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The sign-in doors (sign-in, second-step code, resend a code, forgot / reset password) are closed to an address that a Super Admin blacklisted or that was blocked
 * for too many wrong sign-ins. A whitelisted address always passes. Everything else on the site is untouched: this only looks at those few POST requests, so a
 * person who is already signed in is never thrown out by it.
 */
class EnforceAddressRules
{
    private const DOORS = [
        'login' => 'username',
        'two-factor-challenge' => 'code',
        'two-factor-challenge/send' => 'code',
        'forgot-password' => 'email',
        'reset-password' => 'email',
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('POST') && ($field = self::DOORS[trim($request->path(), '/')] ?? null)) {
            $ip = (string) $request->ip();
            if ($block = AccessGuard::loginBlock($ip)) {
                AccessGuard::hit($ip);
                \App\Services\SecurityAlert::note($ip, 'sign-in while blocked');

                throw ValidationException::withMessages([$field => [$block['message']]]);
            }
        }

        return $next($request);
    }
}
