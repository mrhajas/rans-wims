<?php

namespace App\Http\Requests;

use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/** Fortify's challenge request plus the method the user picked (app | email | sms | recovery). */
class TwoFactorChallengeRequest extends TwoFactorLoginRequest
{
    public function rules()
    {
        return [
            'method' => 'nullable|string|in:app,email,sms,recovery',
            'code' => 'nullable|string|max:20',
            'recovery_code' => 'nullable|string|max:64',
        ];
    }
}
