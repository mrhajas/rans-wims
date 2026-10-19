<?php

namespace App\Rules;

use App\Support\TzPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A mobile number that can be turned into +255XXXXXXXXX (empty is allowed; combine with `required` where it is needed). */
class TzMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (filled($value) && ! TzPhone::isValid((string) $value)) {
            $fail(TzPhone::message());
        }
    }
}
