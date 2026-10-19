<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Counts security events per address; when one address keeps hitting the walls (blocked input, rate limits, refused sign-ins) the administrators are told
 * once, with the address and what it tried. Never throws.
 */
class SecurityAlert
{
    public const THRESHOLD = 8;

    public const WINDOW_MINUTES = 10;

    public static function note(string $ip, string $kind, array $context = []): void
    {
        try {
            $key = 'sec-alert:' . $ip;
            $n = (int) Cache::get($key, 0) + 1;
            Cache::put($key, $n, now()->addMinutes(self::WINDOW_MINUTES));
            if ($n === self::THRESHOLD && ! Cache::has($key . ':sent')) {
                Cache::put($key . ':sent', 1, now()->addHour());
                Log::warning('Security: repeated attempts from one address', ['ip' => $ip, 'kind' => $kind, 'attempts' => $n] + $context);
                if (class_exists(\App\Services\AdminNotificationService::class)) {
                    AdminNotificationService::send(__('Security alert'), __(':n blocked or refused requests from :ip in :m minutes (latest: :k).', ['n' => $n, 'ip' => $ip, 'm' => self::WINDOW_MINUTES, 'k' => $kind]), route('activity', ['module' => 'users_security']));
                }
            }
        } catch (\Throwable) {
            // an alert problem must never become an error page
        }
    }
}
