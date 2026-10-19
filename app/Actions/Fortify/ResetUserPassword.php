<?php

namespace App\Actions\Fortify;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function reset($user, array $input)
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();

        log_activity(__('User has reset password.'), $user, $user, 'Profile');
        \App\Services\UserNotifier::fire('password_reset', $user, __('Password Reset'), $user->name . ' (' . $user->username . ') reset their password with the email link.', $user);
    }
}
