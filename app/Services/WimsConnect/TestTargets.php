<?php

namespace App\Services\WimsConnect;

use App\Models\User;
use App\Support\TzPhone;

/**
 * Where a test message to one person would go, and where WIMS got that address from: the email of the login (or the personal email of the
 * linked staff profile) and the mobile number of the staff profile (or the phone on the login, or the number verified for sign-in codes).
 * Same order of preference as the Recipients book, so a test shows exactly what a real alert would reach.
 */
class TestTargets
{
    /** @return array{email: ?array{address: string, source: string}, sms: ?array{address: string, source: string}} */
    public static function for(User $user): array
    {
        $staff = $user->relationLoaded('staff') ? $user->staff : $user->staff()->first();

        $email = null;
        foreach ([[$user->email, __('the login')], [$staff?->personal_email, __('the staff profile')]] as [$value, $source]) {
            $value = trim((string) $value);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $email = ['address' => $value, 'source' => $source];
                break;
            }
        }

        $sms = null;
        foreach ([[$staff?->mobile_no, __('the staff profile')], [data_get($user->extra_attributes, 'phone'), __('the login')], [$user->two_factor_phone, __('the sign-in code number')]] as [$value, $source]) {
            $n = TzPhone::normalize($value);
            if ($n) {
                $sms = ['address' => $n, 'source' => $source];
                break;
            }
        }

        return ['email' => $email, 'sms' => $sms];
    }
}
