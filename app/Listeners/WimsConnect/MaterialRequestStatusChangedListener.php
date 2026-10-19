<?php

namespace App\Listeners\WimsConnect;

use App\Events\MaterialRequestStatusChanged;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestNotificationRule;
use App\Services\MaterialRequestMessenger;

/**
 * Sibling of App\Listeners\MaterialRequestStatusChangedListener (which does the in-app bell): this one hands the status
 * change to MaterialRequestMessenger, which sends the SMS / email for that step according to the softcoded settings
 * (Material Request Notification -> SMS & Email): channels on/off, audience and templates.
 */
class MaterialRequestStatusChangedListener
{
    public function handle(MaterialRequestStatusChanged $event): void
    {
        $request = $event->materialRequest;

        $eventKey = match ($request->status) {
            MaterialRequest::STATUS_APPROVED => MaterialRequestNotificationRule::EVENT_APPROVED,
            MaterialRequest::STATUS_REJECTED => MaterialRequestNotificationRule::EVENT_REJECTED,
            MaterialRequest::STATUS_PARTIALLY_ISSUED => MaterialRequestNotificationRule::EVENT_PARTIALLY_ISSUED,
            MaterialRequest::STATUS_ISSUED => MaterialRequestNotificationRule::EVENT_ISSUED,
            MaterialRequest::STATUS_CLEARED => MaterialRequestNotificationRule::EVENT_CLEARED,
            MaterialRequest::STATUS_CLOSED => MaterialRequestNotificationRule::EVENT_CLOSED,
            default => null,
        };

        if (! $eventKey) {
            return;
        }

        // One message per status change, even when the same status is set twice (e.g. a second Partially Issued batch).
        app(MaterialRequestMessenger::class)->dispatch($request, $eventKey, auth()->user(), $event->remarks, (string) $request->logs()->max('id'));
    }
}
