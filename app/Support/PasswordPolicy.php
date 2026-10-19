<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** One password rule for every place a password is set (new user, password change, reset, profile): 8 characters or more with upper and lower case and a number. */
class PasswordPolicy
{
    public const MIN = 8;

    public static function rule(): Password
    {
        return Password::min(self::MIN)->mixedCase()->numbers();
    }

    public static function hint(): string
    {
        return __('At least :n characters with an upper case letter, a lower case letter and a number.', ['n' => self::MIN]);
    }
}
