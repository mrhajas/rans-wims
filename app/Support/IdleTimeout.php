<?php

namespace App\Support;

/**
 * Auto logout after inactivity. "Inactivity" is the idle time of the whole login session: any real action (mouse, keys, touch, scroll) in ANY tab of
 * the browser counts, and background requests (polling, the keep-alive of an open tab) do not. The browser reports how long the person has been idle
 * on every request (header X-Idle-Ms); the server keeps the last real activity in the session and signs the person out when it is older than their limit.
 *
 * A person may choose a limit on the profile page (5 minutes to 8 hours) or "Do not log out" (0). Without a choice the limit is 30 minutes, except for a
 * login made with "Remember me" (that means "stay signed in on this device"), which has no limit until the person chooses one.
 */
final class IdleTimeout
{
    public const DEFAULT_MINUTES = 30;

    public const OPTIONS = [5, 10, 15, 30, 45, 60, 120, 240, 480];

    public static function minutesFor($user, bool $remembered = false): int
    {
        if (! $user) {
            return 0;
        }
        $chosen = $user->idle_timeout_minutes;
        if ($chosen !== null) {
            return (int) $chosen === 0 ? 0 : max(1, (int) $chosen);
        }

        return $remembered ? 0 : self::DEFAULT_MINUTES;
    }
}
