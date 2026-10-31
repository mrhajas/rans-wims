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

    /** The full sentence a person sees when the password they typed is too weak (one message instead of one per rule). */
    public static function failMessage(): string
    {
        return __('The password is not strong enough: use at least :n characters with an upper case letter, a lower case letter and a number.', ['n' => self::MIN]);
    }

    /** A new random password that passes the rule above (letters and digits only, no look-alike characters such as O / 0 or l / 1, so it is easy to read out and type). */
    public static function generate(int $length = 10): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $all = $upper . $lower . $digits;
        $pick = fn (string $set) => $set[random_int(0, strlen($set) - 1)];
        $chars = [$pick($upper), $pick($lower), $pick($digits)];
        for ($i = count($chars); $i < max($length, self::MIN); $i++) {
            $chars[] = $pick($all);
        }
        for ($i = count($chars) - 1; $i > 0; $i--) { // shuffle with a secure source
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    public static function hint(): string
    {
        return __('At least :n characters with an upper case letter, a lower case letter and a number.', ['n' => self::MIN]);
    }
}
