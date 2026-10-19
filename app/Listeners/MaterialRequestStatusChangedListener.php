<?php

namespace App\Listeners;

use App\Events\MaterialRequestStatusChanged;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestNotificationRule;
use App\Models\MaterialRequestWorkflowStep;
use App\Notifications\AppNotification;

/**
 * Turns a Material Request status change into bell notifications. Who is told is softcoded (Administration ->
 * Material Request Notification -> Who Gets Notified): each event's role / user list, plus "the requester" and
 * "the Site Engineers assigned to this request's project / warehouse". On top of that, whoever holds the NEXT step
 * (Procurement after approval, the Clear / Complete step after Fully Issued) is always told it is their turn.
 */
class MaterialRequestStatusChangedListener
{
    public function handle(MaterialRequestStatusChanged $event): void
    {
        $request = $event->materialRequest;
        $request->loadMissing('user:id,name', 'warehouse:id,name');

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

        $accountId = getAccountId(1);
        $actor = optional(auth()->user())->name ?: __('a user');
        $titles = [
            MaterialRequestNotificationRule::EVENT_APPROVED => 'Material Request Approved',
            MaterialRequestNotificationRule::EVENT_REJECTED => 'Material Request Rejected',
            MaterialRequestNotificationRule::EVENT_PARTIALLY_ISSUED => 'Material Request Partially Issued',
            MaterialRequestNotificationRule::EVENT_ISSUED => 'Material Request Fully Issued',
            MaterialRequestNotificationRule::EVENT_CLEARED => 'Material Request Cleared',
            MaterialRequestNotificationRule::EVENT_CLOSED => 'Material Request Closed',
        ];

        // The rule's audience (role/user list is never allowed to swallow the requester's own notice).
        $recipients = MaterialRequestNotificationRule::recipientsFor($accountId, $eventKey, $request, (int) $request->user_id);
        $told = [];

        foreach ($recipients as $recipient) {
            $isRequester = (int) $recipient->id === (int) $request->user_id;
            $this->send($recipient, $request, $eventKey, $titles[$eventKey], $this->message($eventKey, $request, $isRequester, $actor), $event->remarks);
            $told[] = (int) $recipient->id;
        }

        // The next step's owners are always told it is their turn (unless they were already told above).
        $nextStep = match ($eventKey) {
            MaterialRequestNotificationRule::EVENT_APPROVED => [MaterialRequestWorkflowStep::STEP_CLEAR, 'is approved and ready for procurement: set the procedure of each item and mark it Partially / Fully Issued.'],
            MaterialRequestNotificationRule::EVENT_ISSUED => [MaterialRequestWorkflowStep::STEP_COMPLETE, 'is Fully Issued and ready to Clear / Complete.'],
            default => null,
        };

        if ($nextStep) {
            MaterialRequestWorkflowStep::assignedUsers($accountId, $nextStep[0])
                ->reject(fn ($u) => (int) $u->id === (int) $request->user_id || in_array((int) $u->id, $told, true))
                ->each(fn ($user) => $this->send($user, $request, $eventKey . '_next', $titles[$eventKey], 'Request ' . $request->reference . ' ' . $nextStep[1], $event->remarks));
        }
    }

    private function send($recipient, MaterialRequest $request, string $type, string $title, string $message, ?string $remarks): void
    {
        $recipient->notify(new AppNotification([
            'module' => 'material_requests',
            'type' => 'material_request_' . $type,
            'title' => $title,
            'message' => $message,
            'status' => $request->status,
            'reference' => $request->reference,
            'remarks' => $remarks,
            'url' => route('material-requests.show', $request->id),
            'created_at' => now()->toDateTimeString(),
        ]));
    }

    private function message(string $eventKey, MaterialRequest $request, bool $isRequester, string $actor): string
    {
        $subject = $isRequester ? 'Your request ' . $request->reference : 'Request ' . $request->reference;
        $where = optional($request->warehouse)->name;
        $suffix = $where && ! $isRequester ? ' (' . $where . ')' : '';

        return match ($eventKey) {
            MaterialRequestNotificationRule::EVENT_APPROVED => $subject . $suffix . ' has been approved by ' . $actor . '.',
            MaterialRequestNotificationRule::EVENT_REJECTED => $subject . $suffix . ' has been rejected by ' . $actor . '.',
            MaterialRequestNotificationRule::EVENT_PARTIALLY_ISSUED => $subject . $suffix . ' has been marked Partially Issued by ' . $actor . '.',
            MaterialRequestNotificationRule::EVENT_ISSUED => $subject . $suffix . ' has been marked Fully Issued by ' . $actor . '.',
            MaterialRequestNotificationRule::EVENT_CLEARED => $subject . $suffix . ' has been cleared / completed by ' . $actor . '. It is now locked.',
            default => $subject . $suffix . ' has been closed.',
        };
    }
}
