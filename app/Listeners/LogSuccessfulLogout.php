<?php

namespace App\Listeners;

class LogSuccessfulLogout
{
    public function handle($event)
    {
        // Signing out ends presence at once — otherwise they'd linger as "Idle" for 15 minutes.
        $event->user?->forceFill(['last_seen_at' => null])->saveQuietly();

        log_activity(__choice('action_text', ['record' => 'User (' . $event->user->username . ')', 'action' => 'logged out']), $event->user, $event->user, 'User');
    }
}
