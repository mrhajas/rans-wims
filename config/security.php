<?php

return [
    /*
     | SQL injection guard (App\Http\Middleware\SqlInjectionGuard). It is a second line of defence: WIMS already sends every value to MySQL as a bound
     | parameter, which is what really stops injection. The guard refuses input that LOOKS like an attack (union select, "; drop table", ' or '1'='1,
     | sleep(5) ...), writes it to the security log, and on the sign-in pages only accepts plain user names, e-mail addresses and codes.
     |   block = refuse and log (default), log = only log, off = switched off
     */
    'sqli_guard' => env('SQLI_GUARD', 'block'),

    // Response headers on every page (X-Frame-Options, nosniff, referrer and permissions policy, HSTS on HTTPS). Switch off only to diagnose.
    'headers' => env('SECURITY_HEADERS', true),

    /*
     | Server-side rate limits for every web request (App\Http\Middleware\ThrottleRequestFloods). The numbers sit far above normal use; they only stop scripts.
     */
    'rate_limit_enabled' => env('RATE_LIMITS', true),
    'rate_limits' => [
        'per_user_minute' => (int) env('RL_USER_MINUTE', 900),
        'per_guest_minute' => (int) env('RL_GUEST_MINUTE', 600),
        'writes_per_minute' => (int) env('RL_WRITES_MINUTE', 240),
        'auth_per_ip_minute' => (int) env('RL_AUTH_IP_MINUTE', 120),
        'auth_per_ip_hour' => (int) env('RL_AUTH_IP_HOUR', 1200),
        'password_checks_per_10_min' => (int) env('RL_PASSWORD_CHECKS', 12),
    ],

    /*
     | Sign-in restriction by address (App\Services\Security\AccessGuard). More than `max_failures` wrong sign-ins (password or two-factor code) from one address
     | within `window_seconds` blocks that address; each new block of the same address is longer (`block_minutes`, the last one repeats). The memory of earlier blocks is
     | forgotten after `forget_after_hours` without a new block. A Super Admin can whitelist an address (never counted, never blocked) or blacklist one
     | (Login Logs > Blacklist / Whitelist).
     */
    // Login Logs ask for the person's own password again; it stays confirmed this many minutes (renewed while they work there)
    'login_logs_unlock_minutes' => (int) env('LOGIN_LOGS_UNLOCK_MINUTES', 10),

    'login' => [
        'enabled' => (bool) env('LOGIN_BLOCK', true),
        'max_failures' => (int) env('LOGIN_BLOCK_FAILURES', 25),
        'window_seconds' => (int) env('LOGIN_BLOCK_WINDOW', 180),
        'block_minutes' => [10, 20, 60, 1440],
        'forget_after_hours' => (int) env('LOGIN_BLOCK_FORGET_HOURS', 24),
        'alert_from_minutes' => 60,            // blocks this long or longer are told to the Super Admins (bell)
    ],

    /*
     | Resending the sign-in code by Email or SMS (App\Services\TwoFactorOtp). The first `free_sends` codes go out as usual (only the short anti-spam pause);
     | after that every new code needs a longer wait (`waits_seconds`, in order), and once those are used up the account is blocked from asking for codes
     | for `block_hours`. A successful sign-in starts the count again.
     */
    'otp' => [
        'enabled' => (bool) env('OTP_ESCALATION', true),
        'free_sends' => 3,
        'waits_seconds' => [180, 300, 600, 1200],
        'block_hours' => 24,
        'forget_after_hours' => 24,
    ],
];
