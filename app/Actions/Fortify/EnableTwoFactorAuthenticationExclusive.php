<?php

namespace App\Actions\Fortify;

use App\Services\TwoFactorOtp;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * A user may use the authenticator app OR Email/SMS codes — never both. Fortify's own enable action
 * (bound to this class in FortifyServiceProvider) refuses to start the authenticator while an
 * Email/SMS code method is on; the reverse rule lives in TwoFactorOtpSettingsController.
 */
class EnableTwoFactorAuthenticationExclusive extends EnableTwoFactorAuthentication
{
    public function __invoke($user, $force = false)
    {
        if (app(TwoFactorOtp::class)->hasOtpMethod($user)) {
            throw ValidationException::withMessages(['two_factor' => [TwoFactorOtp::exclusiveMessage(false)]]);
        }

        parent::__invoke($user, $force);
    }
}
