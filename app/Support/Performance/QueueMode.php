<?php

namespace App\Support\Performance;

use Illuminate\Support\Facades\Cache;

/**
 * "Send in the background": SMS, e-mail and bell alerts are queued and sent by a worker that runs every minute from the scheduler, so a save no longer waits for them.
 * It is used only while the switch in Settings is on AND the worker has been seen running in the last 2.5 minutes. If the worker stops (the cron job is missing or the host
 * paused it) WIMS quietly goes back to sending immediately, so nothing is ever left waiting in a queue nobody is emptying.
 */
class QueueMode
{
    public const BEAT_KEY = 'wims.queue.beat';
    private const ALIVE_SECONDS = 150;

    public static function apply(): void
    {
        try {
            if (PerfSettings::get('perf_queue_background') === 1 && self::alive()) {
                config(['queue.default' => 'database']);
            }
        } catch (\Throwable $e) {
            // keep the configured connection
        }
    }

    public static function beat(): void
    {
        Cache::forever(self::BEAT_KEY, time());
    }

    public static function lastBeat(): ?int
    {
        $t = Cache::get(self::BEAT_KEY);

        return $t ? (int) $t : null;
    }

    public static function alive(): bool
    {
        $t = self::lastBeat();

        return $t !== null && (time() - $t) <= self::ALIVE_SECONDS;
    }
}
