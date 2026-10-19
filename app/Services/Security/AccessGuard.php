<?php

namespace App\Services\Security;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in restriction in one place (rules in config/security.php, state in the security_* tables):
 *
 *  - ADDRESSES: a Super Admin's whitelist (never counted, never blocked) and blacklist (always refused) win over everything; otherwise more than N wrong sign-ins
 *    within a few minutes block the address, each new block longer than the last (10 min, 20, 60, then 24 hours).
 *  - CODE REQUESTS (Email/SMS sign-in codes): the first few go out normally, then every new code needs a longer wait, then the account is blocked for 24 hours.
 *
 * It sits next to (does not replace) the existing limits: the per-minute sign-in throttle, the flood limiter, the SQL guard and the sign-in question. It never throws:
 * when the tables are missing (an older database, the simulation before its sync) it simply does nothing.
 */
class AccessGuard
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('security_ip_rules') && Schema::hasTable('security_counters');
    }

    /** for tests that switch databases */
    public static function forget(): void
    {
        self::$ready = null;
    }

    // ------------------------------------------------------------------ addresses

    public static function rule(string $ip): ?object
    {
        if (! self::ready() || $ip === '') {
            return null;
        }
        $r = DB::table('security_ip_rules')->where('ip', $ip)->first();

        return $r && (! $r->expires_at || Carbon::parse($r->expires_at)->isFuture()) ? $r : null;
    }

    public static function whitelisted(string $ip): bool
    {
        return optional(self::rule($ip))->type === 'white';
    }

    /**
     * Why this address may not sign in right now, or null. kind = blacklist (manual) | blocked (automatic); seconds = what is left (null = until a Super Admin lifts it).
     *
     * @return array{kind:string,seconds:?int,until:?string,message:string}|null
     */
    public static function loginBlock(string $ip): ?array
    {
        try {
            if (! config('security.login.enabled') || ! self::ready() || $ip === '') {
                return null;
            }
            $rule = self::rule($ip);
            if ($rule && $rule->type === 'white') {
                return null;
            }
            if ($rule) {
                return ['kind' => 'blacklist', 'seconds' => null, 'until' => $rule->expires_at, 'message' => __('Sign-in from your address has been blocked by an administrator. Please contact the administrator.')];
            }
            $c = self::counter('login_fail', 'ip:' . $ip);
            if ($c && $c->blocked_until && Carbon::parse($c->blocked_until)->isFuture()) {
                $left = max(1, now()->diffInSeconds(Carbon::parse($c->blocked_until), false));

                return ['kind' => 'blocked', 'seconds' => (int) $left, 'until' => $c->blocked_until,
                    'message' => __('Too many wrong sign-in attempts. Sign-in from your address is paused for :t. Please try again then.', ['t' => self::human((int) $left)])];
            }
        } catch (\Throwable $e) {
            Log::warning('AccessGuard loginBlock failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** an attempt refused because of a block (counted, so the Super Admin sees how persistent it is) */
    public static function hit(string $ip): void
    {
        try {
            if (! self::ready()) {
                return;
            }
            if (self::rule($ip)) {
                DB::table('security_ip_rules')->where('ip', $ip)->increment('hits', 1, ['last_hit_at' => now()]);
            } else {
                DB::table('security_counters')->where('scope', 'login_fail')->where('subject', 'ip:' . $ip)->increment('hits');
            }
        } catch (\Throwable) {
        }
    }

    /** One wrong sign-in (password or two-factor code) from this address. Returns the block that this one caused, if any. */
    public static function recordLoginFailure(string $ip, string $why = 'password'): ?array
    {
        try {
            $cfg = config('security.login');
            if (! $cfg['enabled'] || ! self::ready() || $ip === '' || self::whitelisted($ip)) {
                return null;
            }
            $result = null;
            DB::transaction(function () use ($ip, $cfg, &$result) {
                $subject = 'ip:' . $ip;
                $c = DB::table('security_counters')->where('scope', 'login_fail')->where('subject', $subject)->lockForUpdate()->first();
                if (! $c) {
                    try {
                        DB::table('security_counters')->insert(['scope' => 'login_fail', 'subject' => $subject, 'created_at' => now(), 'updated_at' => now()]);
                    } catch (\Throwable) {
                    }
                    $c = DB::table('security_counters')->where('scope', 'login_fail')->where('subject', $subject)->lockForUpdate()->first();
                }
                if ($c->blocked_until && Carbon::parse($c->blocked_until)->isFuture()) {
                    DB::table('security_counters')->where('id', $c->id)->increment('hits');

                    return;                                                  // already blocked: nothing to count
                }
                $start = $c->window_started_at ? Carbon::parse($c->window_started_at) : null;
                $count = ($start && $start->diffInSeconds(now()) <= $cfg['window_seconds']) ? (int) $c->count + 1 : 1;
                $start = $count === 1 ? now() : $start;
                // earlier blocks are forgotten after a quiet day
                $level = (int) $c->level;
                if ($level > 0 && $c->last_block_at && Carbon::parse($c->last_block_at)->diffInHours(now()) >= $cfg['forget_after_hours'] && (! $c->blocked_until || Carbon::parse($c->blocked_until)->isPast())) {
                    $level = 0;
                }
                $set = ['count' => $count, 'window_started_at' => $start, 'last_at' => now(), 'level' => $level, 'updated_at' => now()];
                if ($count > $cfg['max_failures']) {
                    $steps = array_values($cfg['block_minutes']);
                    $minutes = (int) $steps[min($level, count($steps) - 1)];
                    $set = array_merge($set, ['count' => 0, 'window_started_at' => null, 'level' => $level + 1, 'blocked_until' => now()->addMinutes($minutes), 'last_block_at' => now(), 'blocks_total' => (int) $c->blocks_total + 1, 'note' => 'Automatic block: ' . $minutes . ' minutes']);
                    $result = ['minutes' => $minutes, 'level' => $level + 1];
                }
                DB::table('security_counters')->where('id', $c->id)->update($set);
            });
            if ($result && $result['minutes'] >= (int) $cfg['alert_from_minutes']) {
                self::alert($ip, $result['minutes'], $why);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('AccessGuard recordLoginFailure failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private static function alert(string $ip, int $minutes, string $why): void
    {
        try {
            \App\Services\AdminNotificationService::send(
                __('Security alert'),
                __('The address :ip was blocked from signing in for :t after repeated wrong attempts (:w).', ['ip' => $ip, 't' => self::human($minutes * 60), 'w' => $why]),
                route('login-logs.addresses', 'blacklist')
            );
        } catch (\Throwable) {
        }
    }

    /** lifts an automatic block (and forgets the address's history) */
    public static function unblock(string $ip): void
    {
        if (self::ready()) {
            DB::table('security_counters')->where('scope', 'login_fail')->where('subject', 'ip:' . $ip)->delete();
        }
    }

    // ------------------------------------------------------------------ sign-in codes

    /**
     * May this account ask for another sign-in code now?
     *
     * @return array{ok:bool,wait:int,blocked:bool,message:?string,sent:int}
     */
    public static function otpGate(int $userId, string $purpose = 'login'): array
    {
        $open = ['ok' => true, 'wait' => 0, 'blocked' => false, 'message' => null, 'sent' => 0];
        try {
            $cfg = config('security.otp');
            if (! $cfg['enabled'] || ! self::ready() || $purpose !== 'login') {
                return $open;
            }
            $c = self::counter('otp_send', 'user:' . $userId . ':' . $purpose);
            if (! $c) {
                return $open;
            }
            if ($c->blocked_until && Carbon::parse($c->blocked_until)->isFuture()) {
                return ['ok' => false, 'wait' => 0, 'blocked' => true, 'sent' => (int) $c->count,
                    'message' => __('Too many codes were requested. Sending sign-in codes to this account is blocked for :t. Please contact an administrator.', ['t' => self::human((int) now()->diffInSeconds(Carbon::parse($c->blocked_until), false))])];
            }
            if ($c->last_at && Carbon::parse($c->last_at)->diffInHours(now()) >= $cfg['forget_after_hours']) {
                return $open;                                                        // a quiet day starts the count again
            }
            $n = (int) $c->count;
            if ($n < $cfg['free_sends']) {
                return $open + ['sent' => $n] + $open;
            }
            $waits = array_values($cfg['waits_seconds']);
            $i = $n - $cfg['free_sends'];
            if ($i >= count($waits)) {
                // every wait has been used: the next request blocks the account
                DB::table('security_counters')->where('id', $c->id)->update(['blocked_until' => now()->addHours($cfg['block_hours']), 'last_block_at' => now(), 'blocks_total' => (int) $c->blocks_total + 1, 'note' => 'Too many sign-in code requests', 'updated_at' => now()]);

                return ['ok' => false, 'wait' => 0, 'blocked' => true, 'sent' => $n,
                    'message' => __('Too many codes were requested. Sending sign-in codes to this account is blocked for :t. Please contact an administrator.', ['t' => self::human($cfg['block_hours'] * 3600)])];
            }
            $left = (int) $waits[$i] - (int) Carbon::parse($c->last_at)->diffInSeconds(now());
            if ($left > 0) {
                return ['ok' => false, 'wait' => $left, 'blocked' => false, 'sent' => $n, 'message' => __('Please wait :t before requesting another code.', ['t' => self::human($left)])];
            }

            return ['ok' => true, 'wait' => 0, 'blocked' => false, 'message' => null, 'sent' => $n];
        } catch (\Throwable $e) {
            Log::warning('AccessGuard otpGate failed', ['error' => $e->getMessage()]);

            return $open;
        }
    }

    /** A code was sent. Returns the wait before the NEXT one (0 = only the usual short pause). */
    public static function otpSent(int $userId, string $purpose = 'login'): int
    {
        try {
            $cfg = config('security.otp');
            if (! $cfg['enabled'] || ! self::ready() || $purpose !== 'login') {
                return 0;
            }
            $subject = 'user:' . $userId . ':' . $purpose;
            $count = 1;
            DB::transaction(function () use ($subject, $cfg, &$count) {
                $c = DB::table('security_counters')->where('scope', 'otp_send')->where('subject', $subject)->lockForUpdate()->first();
                if (! $c) {
                    DB::table('security_counters')->insert(['scope' => 'otp_send', 'subject' => $subject, 'count' => 1, 'last_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

                    return;
                }
                $stale = $c->last_at && Carbon::parse($c->last_at)->diffInHours(now()) >= $cfg['forget_after_hours'] && (! $c->blocked_until || Carbon::parse($c->blocked_until)->isPast());
                $count = $stale ? 1 : (int) $c->count + 1;
                DB::table('security_counters')->where('id', $c->id)->update(['count' => $count, 'last_at' => now(), 'blocked_until' => $stale ? null : $c->blocked_until, 'updated_at' => now()]);
            });
            $waits = array_values($cfg['waits_seconds']);
            $i = $count - $cfg['free_sends'];

            return $i >= 0 && $i < count($waits) ? (int) $waits[$i] : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** a successful sign-in starts the code count again (a block stays: only a Super Admin lifts it) */
    public static function otpReset(int $userId, string $purpose = 'login'): void
    {
        try {
            if (self::ready()) {
                DB::table('security_counters')->where('scope', 'otp_send')->where('subject', 'user:' . $userId . ':' . $purpose)
                    ->where(fn ($q) => $q->whereNull('blocked_until')->orWhere('blocked_until', '<=', now()))->delete();
            }
        } catch (\Throwable) {
        }
    }

    public static function otpUnblock(int $counterId): void
    {
        if (self::ready()) {
            DB::table('security_counters')->where('id', $counterId)->where('scope', 'otp_send')->delete();
        }
    }

    // ------------------------------------------------------------------ helpers

    private static function counter(string $scope, string $subject): ?object
    {
        return DB::table('security_counters')->where('scope', $scope)->where('subject', $subject)->first();
    }

    /** 90 -> "2 minutes", 86400 -> "24 hours" */
    public static function human(int $seconds): string
    {
        $seconds = max(1, $seconds);
        if ($seconds >= 3600) {
            $h = (int) ceil($seconds / 3600);

            return $h === 1 ? __('1 hour') : __(':n hours', ['n' => $h]);
        }
        if ($seconds >= 60) {
            $m = (int) ceil($seconds / 60);

            return $m === 1 ? __('1 minute') : __(':n minutes', ['n' => $m]);
        }

        return __(':n seconds', ['n' => $seconds]);
    }
}
