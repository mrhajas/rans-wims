<?php

namespace App\Services;

use App\Services\Workflow\WorkflowSettings;

/**
 * A tiny question the senders ask before they choose between two events: is this event switched on right now (a saved setting over the
 * starting values)? Used where a finer event (for example "permanently deleted") replaces a coarser one ("deleted") only once a Super
 * Admin has switched the finer one on, so nobody who is told today stops being told when the new event is added.
 */
class WorkflowGate
{
    public static function on(string $module, string $event, int $accountId = 1): bool
    {
        try {
            return (bool) WorkflowSettings::effective($module, $accountId, $event)['is_enabled'];
        } catch (\Throwable) {
            return false;
        }
    }

    /** $preferred when it is switched on, otherwise $fallback (the event that told people before $preferred existed). */
    public static function pick(string $module, string $preferred, string $fallback, int $accountId = 1): string
    {
        return self::on($module, $preferred, $accountId) ? $preferred : $fallback;
    }
}
