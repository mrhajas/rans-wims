<?php

namespace App\Services;

use App\Mail\TwoFactorOtpMail;
use App\Models\User;
use App\Models\WimsConnectLog;
use App\Services\WimsConnect\GatewayManager;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One-time sign-in / enrolment codes sent by Email or SMS — the two extra two-factor methods next to the
 * authenticator app. Codes are 6 digits, stored only as an HMAC (never in clear), expire after 10 minutes
 * (mobile networks can take minutes to deliver an SMS), allow 5 wrong tries, can be re-sent at most every 45
 * seconds and 5 times per 15 minutes per user, and are single-use. A re-send does NOT cancel the codes sent
 * before it: a slow earlier SMS/email still works until its own 10 minutes are up (the last 3 are kept). SMS goes through the same WIMS Connect gateway as every other SMS (and shows in its delivery
 * log, with the code itself hidden).
 */
class TwoFactorOtp
{
    public const CHANNELS = ['email', 'sms'];

    public const TTL_SECONDS = 600;

    public const MAX_ATTEMPTS = 5;

    public const COOLDOWN_SECONDS = 45;

    public const MAX_SENDS = 5;

    public const SEND_WINDOW_SECONDS = 900;

    /** Channels the user has switched on (and that can currently work), in display order. */
    public function enabledChannels(User $user): array
    {
        $channels = [];

        if ($user->two_factor_email_enabled_at && $user->email) {
            $channels[] = 'email';
        }
        if ($user->two_factor_sms_enabled_at && $user->two_factor_phone) {
            $channels[] = 'sms';
        }

        return $channels;
    }

    public function hasAuthenticator(User $user): bool
    {
        return ! empty($user->two_factor_secret);
    }

    /** Email or SMS code switched on (regardless of whether the destination is currently usable). */
    public function hasOtpMethod(User $user): bool
    {
        return ! empty($user->two_factor_email_enabled_at) || ! empty($user->two_factor_sms_enabled_at);
    }

    public static function exclusiveMessage(bool $authenticatorIsOn): string
    {
        return $authenticatorIsOn
            ? __('You can use either the authenticator app or Email/SMS codes, not both. Turn off the authenticator app first to use Email or SMS codes.')
            : __('You can use either the authenticator app or Email/SMS codes, not both. Turn off Email and SMS codes first to use the authenticator app.');
    }

    public function hasAnyMethod(User $user): bool
    {
        return ! empty($user->two_factor_secret) || ! empty($this->enabledChannels($user));
    }

    public function smsAvailable(): bool
    {
        return FeatureManager::isEnabled('wims_connect') && FeatureManager::isEnabled('wims_connect_sms');
    }

    public function destination(User $user, string $channel): ?string
    {
        return match ($channel) {
            'email' => $user->email,
            'sms' => $user->two_factor_phone,
            default => null,
        };
    }

    public function mask(string $channel, ?string $destination): string
    {
        if (! $destination) {
            return '';
        }

        if ($channel === 'email') {
            [$local, $domain] = array_pad(explode('@', $destination, 2), 2, '');
            $dot = strrpos($domain, '.');
            $host = $dot === false ? $domain : substr($domain, 0, $dot);
            $tld = $dot === false ? '' : substr($domain, $dot);

            return Str::substr($local, 0, 1) . '•••@' . Str::substr($host, 0, 1) . '•••' . $tld;
        }

        $digits = preg_replace('/\D/', '', $destination);

        return '+' . Str::substr($digits, 0, 3) . ' ••• ••' . Str::substr($digits, -3);
    }

    /** Accepts 0712345678, 255712345678, +255 712 345 678 … and returns +255712345678, or null if it can't be a phone number. */
    public function normalizePhone(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if ($tz = \App\Support\TzPhone::normalize($raw)) {
            return $tz;
        }

        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw, '+')) {
            // already international
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '255' . substr($digits, 1);
        }

        return preg_match('/^\d{9,15}$/', $digits) ? '+' . $digits : null;
    }

    /**
     * @return array{ok:bool, error:?string, cooldown:int, masked:string}
     */
    public function send(User $user, string $channel, string $purpose = 'login', ?string $destination = null): array
    {
        $destination ??= $this->destination($user, $channel);
        $masked = $this->mask($channel, $destination);

        if (! in_array($channel, self::CHANNELS, true) || ! $destination) {
            return $this->fail(__('That verification method is not available for your account.'), 0, $masked);
        }

        if ($channel === 'sms' && ! $this->smsAvailable()) {
            return $this->fail(__('SMS codes are not available right now. Please choose another method.'), 0, $masked);
        }

        // the first few codes go out as usual; after that every new code needs a longer wait, then the account is blocked for 24 hours (config/security.php)
        $gate = \App\Services\Security\AccessGuard::otpGate((int) $user->id, $purpose);
        if (! $gate['ok']) {
            return $this->fail($gate['message'], (int) $gate['wait'], $masked);
        }

        $state = Cache::get($this->key($user, $purpose));
        $wait = $state ? max(0, self::COOLDOWN_SECONDS - (time() - $state['sent_at'])) : 0;
        if ($wait > 0 && ($state['channel'] ?? null) === $channel) {
            return $this->fail(__('Please wait :seconds seconds before requesting another code.', ['seconds' => $wait]), $wait, $masked);
        }

        $limiterKey = 'two-factor-otp-send:' . $user->id . ':' . $purpose;
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_SENDS)) {
            $seconds = RateLimiter::availableIn($limiterKey);

            return $this->fail(__('Too many codes requested. Try again in :minutes minute(s).', ['minutes' => max(1, (int) ceil($seconds / 60))]), $seconds, $masked);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $delivered = $channel === 'email'
            ? $this->deliverEmail($user, $destination, $code, $purpose)
            : $this->deliverSms($user, $destination, $code);

        if (! $delivered) {
            return $this->fail(
                $channel === 'sms'
                    ? __('We could not send the SMS. Please try again or choose another method.')
                    : __('We could not send the email. Please try again or choose another method.'),
                0,
                $masked
            );
        }

        RateLimiter::hit($limiterKey, self::SEND_WINDOW_SECONDS);

        // Keep earlier codes of the SAME channel that are still inside their own lifetime (max 3 in total),
        // so a late-arriving SMS isn't killed by pressing Resend.
        $previous = ($state && ($state['channel'] ?? null) === $channel) ? $this->liveCodes($state) : [];
        $codes = array_slice(array_merge($previous, [['hash' => $this->hash($user, $purpose, $code), 'sent_at' => time()]]), -3);

        Cache::put($this->key($user, $purpose), [
            'codes' => $codes,
            'channel' => $channel,
            'destination' => $destination,
            'attempts' => ($state && ($state['channel'] ?? null) === $channel) ? (int) ($state['attempts'] ?? 0) : 0,
            'sent_at' => time(),
        ], self::TTL_SECONDS);

        $next = \App\Services\Security\AccessGuard::otpSent((int) $user->id, $purpose);

        return ['ok' => true, 'error' => null, 'cooldown' => max(self::COOLDOWN_SECONDS, $next), 'masked' => $masked];
    }

    /** @return array{ok:bool, error:?string} */
    public function verify(User $user, string $channel, string $code, string $purpose = 'login'): array
    {
        $key = $this->key($user, $purpose);
        $state = Cache::get($key);
        $live = $state ? $this->liveCodes($state) : [];

        if (! $state || ($state['channel'] ?? null) !== $channel || ! $live) {
            return ['ok' => false, 'error' => __('That code is incorrect or has expired. Request a new code.')];
        }

        if (($state['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return ['ok' => false, 'error' => __('Too many incorrect attempts. Request a new code.')];
        }

        $given = $this->hash($user, $purpose, preg_replace('/\s+/', '', $code));
        $match = false;
        foreach ($live as $entry) {
            $match = hash_equals($entry['hash'], $given) || $match; // no early exit
        }

        if (! $match) {
            $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
            Cache::put($key, $state, max(1, self::TTL_SECONDS - (time() - $state['sent_at'])));

            return ['ok' => false, 'error' => __('That code is incorrect or has expired. Request a new code.')];
        }

        Cache::forget($key);

        return ['ok' => true, 'error' => null];
    }

    /** Codes in this state that are still inside their own lifetime. */
    protected function liveCodes(array $state): array
    {
        return array_values(array_filter($state['codes'] ?? [], fn ($c) => (time() - $c['sent_at']) < self::TTL_SECONDS));
    }

    /** What the challenge page needs to resume its countdown after a refresh. */
    public function state(User $user, string $purpose = 'login'): ?array
    {
        $state = Cache::get($this->key($user, $purpose));
        if (! $state) {
            return null;
        }

        return [
            'channel' => $state['channel'],
            'masked' => $this->mask($state['channel'], $state['destination'] ?? null),
            'cooldown' => max(max(0, self::COOLDOWN_SECONDS - (time() - $state['sent_at'])), (int) \App\Services\Security\AccessGuard::otpGate((int) $user->id, $purpose)['wait']),
            'expires_in' => max(0, self::TTL_SECONDS - (time() - $state['sent_at'])),
        ];
    }

    public function forget(User $user, string $purpose = 'login'): void
    {
        Cache::forget($this->key($user, $purpose));
    }


    /** Where the pending code (enrolment) was sent — read before verify() consumes it. */
    public function pendingDestination(User $user, string $purpose): ?string
    {
        return Cache::get($this->key($user, $purpose))['destination'] ?? null;
    }

    /** Everything the Profile page needs to show and manage the Email/SMS methods. */
    public function settingsFor(User $user): array
    {
        $suggested = \App\Models\WimsConnectRecipientChannel::where('user_id', $user->id)->where('channel', 'sms')->value('address');

        return [
            'authenticator' => ! empty($user->two_factor_secret),
            'email' => [
                'enabled' => (bool) $user->two_factor_email_enabled_at,
                'masked' => $this->mask('email', $user->email),
            ],
            'sms' => [
                'enabled' => (bool) $user->two_factor_sms_enabled_at,
                'masked' => $this->mask('sms', $user->two_factor_phone),
                'available' => $this->smsAvailable(),
                'suggested_phone' => $suggested,
            ],
            'ttl_minutes' => (int) (self::TTL_SECONDS / 60),
        ];
    }

    // ------------------------------------------------------------------

    protected function deliverEmail(User $user, string $to, string $code, string $purpose): bool
    {
        try {
            // config('mail.otp_mailer') is null in normal use (= the default mailer); see config/mail.php.
            Mail::mailer(config('mail.otp_mailer') ?: null)->to($to)->send(new TwoFactorOtpMail($code, (int) (self::TTL_SECONDS / 60), $user->name, $purpose));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function deliverSms(User $user, string $to, string $code): bool
    {
        $text = 'WIMS verification code: ' . $code . '. Valid for ' . (int) (self::TTL_SECONDS / 60) . ' minutes. Do not share this code with anyone.';

        try {
            $result = app(GatewayManager::class)->send('sms', $to, new OutboundMessage($text));
        } catch (\Throwable $e) {
            report($e);
            $result = null;
        }

        // Audit trail in the normal WIMS Connect delivery log — the code itself is never written to it.
        try {
            WimsConnectLog::create([
                'idempotency_key' => 'two_factor_otp:' . Str::uuid(),
                'event_key' => 'two_factor_otp',
                'source_type' => User::class,
                'source_id' => $user->id,
                'recipient_user_id' => $user->id,
                'address' => $to,
                'channel' => 'sms',
                'message' => 'WIMS verification code (code hidden)',
                'status' => $result && $result->success ? 'SENT' : 'FAILED',
                'error_message' => $result && ! $result->success ? Str::limit(strip_tags((string) $result->error), 300) : null,
                'sent_at' => $result && $result->success ? now() : null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return (bool) ($result && $result->success);
    }

    protected function fail(string $message, int $cooldown, string $masked): array
    {
        return ['ok' => false, 'error' => $message, 'cooldown' => $cooldown, 'masked' => $masked];
    }

    protected function key(User $user, string $purpose): string
    {
        return 'two-factor-otp:' . $user->id . ':' . $purpose;
    }

    protected function hash(User $user, string $purpose, string $code): string
    {
        return hash_hmac('sha256', $code . '|' . $user->id . '|' . $purpose, (string) config('app.key'));
    }
}
