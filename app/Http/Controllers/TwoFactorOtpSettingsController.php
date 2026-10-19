<?php

namespace App\Http\Controllers;

use App\Services\TwoFactorOtp;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lets a signed-in user turn the Email / SMS code methods on and off. Switching one on is proved by
 * sending a code to that address/number and entering it (so nobody can enrol a number they don't hold).
 * All routes sit behind password confirmation, like Jetstream's own authenticator-app setup.
 */
class TwoFactorOtpSettingsController extends Controller
{
    public function __construct(protected TwoFactorOtp $otp)
    {
        $this->setAbilities([]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'channel' => 'required|string|in:email,sms',
            'phone' => 'nullable|string|max:32',
        ]);

        if ($this->otp->hasAuthenticator($request->user())) {
            throw ValidationException::withMessages(['channel' => [TwoFactorOtp::exclusiveMessage(true)]]);
        }

        $user = $request->user();
        $channel = $data['channel'];

        if ($channel === 'sms') {
            if (! $this->otp->smsAvailable()) {
                throw ValidationException::withMessages(['phone' => [__('SMS codes are not available right now. Ask your administrator to enable SMS in WIMS Connect.')]]);
            }
            $destination = $this->otp->normalizePhone($data['phone'] ?? $user->two_factor_phone);
            if (! $destination) {
                throw ValidationException::withMessages(['phone' => [__('Enter a valid mobile number, for example 0712 345 678 or +255 712 345 678.')]]);
            }
        } else {
            $destination = $user->email;
            if (! filter_var($destination, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['channel' => [__('Your account does not have a valid email address.')]]);
            }
        }

        $result = $this->otp->send($user, $channel, 'enable-' . $channel, $destination);

        if (! $result['ok']) {
            throw ValidationException::withMessages([$channel === 'sms' ? 'phone' : 'code' => [$result['error']]]);
        }

        return response()->json(['ok' => true, 'masked' => $result['masked'], 'cooldown' => $result['cooldown']]);
    }

    public function enable(Request $request)
    {
        $data = $request->validate([
            'channel' => 'required|string|in:email,sms',
            'code' => 'required|string|max:20',
        ]);

        if ($this->otp->hasAuthenticator($request->user())) {
            throw ValidationException::withMessages(['channel' => [TwoFactorOtp::exclusiveMessage(true)]]);
        }

        $user = $request->user();
        $channel = $data['channel'];
        $purpose = 'enable-' . $channel;

        $destination = $this->otp->pendingDestination($user, $purpose);
        $result = $this->otp->verify($user, $channel, $data['code'], $purpose);

        if (! $result['ok'] || ! $destination) {
            throw ValidationException::withMessages(['code' => [$result['error'] ?? __('That code is incorrect or has expired. Request a new code.')]]);
        }

        $user->forceFill($channel === 'sms'
            ? ['two_factor_sms_enabled_at' => now(), 'two_factor_phone' => $destination]
            : ['two_factor_email_enabled_at' => now()]
        )->save();

        log_activity($channel === 'sms' ? __('SMS two-factor code enabled.') : __('Email two-factor code enabled.'), null, $user, 'Security');

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $channel)
    {
        abort_unless(in_array($channel, TwoFactorOtp::CHANNELS, true), 404);

        $user = $request->user();

        $user->forceFill($channel === 'sms'
            ? ['two_factor_sms_enabled_at' => null, 'two_factor_phone' => null]
            : ['two_factor_email_enabled_at' => null]
        )->save();

        $this->otp->forget($user, 'enable-' . $channel);

        log_activity($channel === 'sms' ? __('SMS two-factor code disabled.') : __('Email two-factor code disabled.'), null, $user, 'Security');

        return response()->json(['ok' => true]);
    }
}
