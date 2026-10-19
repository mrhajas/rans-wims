<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Rules\Password;

trait PasswordValidationRules
{
    protected function passwordRules()
    {
        return ['required', 'string', \App\Support\PasswordPolicy::rule(), 'confirmed'];
    }
}
