<?php

namespace App\Actions\Fortify;

use App\Services\TwoFactorOtp;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

/**
 * Fortify only sends a user to the 2FA challenge when they have an authenticator-app secret. This
 * also challenges users whose only second factor is an Email or SMS code. The credentials check
 * (including the Maintenance Lock, see FortifyServiceProvider) is Fortify's own validateCredentials().
 */
class RedirectIfTwoFactorOrOtp extends RedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user && app(TwoFactorOtp::class)->hasAnyMethod($user)) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        return $next($request);
    }
}
