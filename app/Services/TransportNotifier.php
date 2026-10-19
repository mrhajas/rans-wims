<?php

namespace App\Services;

use App\Services\Workflow\WorkflowMessenger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The one door every Transport Routes event goes through (trips, vehicles, locations, Loading & Unloading, settings): it hands the event to
 * the Transport Routes workflow page's settings (Who Gets Notified / Messages). A failure to notify never stops the work that triggered it.
 */
class TransportNotifier
{
    /** Set while sample data is being seeded so nobody is told about it. */
    public static bool $muted = false;

    public static function notify(string $eventKey, string $title, string $message, string $url, ?Model $source = null, ?int $ownerId = null, array $details = []): void
    {
        if (self::$muted) {
            return;
        }

        try {
            $event = str_replace('-', '_', preg_replace('/^transport-/', '', $eventKey));
            WorkflowMessenger::dispatch('transport', $event, $source, auth()->user(), [
                'account_id' => $source->account_id ?? auth()->user()?->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => $url,
                'details' => $details, 'owner_user_id' => (int) $ownerId,
            ]);
        } catch (\Throwable $e) {
            Log::error('TransportNotifier failed', ['event' => $eventKey, 'error' => $e->getMessage()]);
        }
    }
}
