<?php

namespace App\Listeners;

use App\Events\CaseTicketStatusChanged;
use App\Models\CaseTicket;
use App\Models\CaseTrackingNotificationRule;
use App\Services\CaseWorkflowNotifier;

/**
 * Who is told, on which channels and with which words is set on the Case
 * Tracking workflow page, one event per status the case moves into.
 */
class CaseTicketStatusChangedListener
{
    public function handle(CaseTicketStatusChanged $event): void
    {
        $case = $event->caseTicket;

        // A move INTO In Review means two different things depending on
        // where it came from — the ordinary Submitted -> In Review handoff,
        // or a reopen out of Resolved/Closed — each with its own
        // configurable audience (a Resolved case reopening is a much
        // bigger deal than the routine first-review handoff).
        $wasReopen = $case->status === CaseTicket::STATUS_IN_REVIEW
            && in_array($event->previousStatus, [CaseTicket::STATUS_RESOLVED, CaseTicket::STATUS_CLOSED], true);

        $eventKey = match (true) {
            $case->status === CaseTicket::STATUS_SUBMITTED => CaseTrackingNotificationRule::EVENT_SUBMITTED,
            $wasReopen => CaseTrackingNotificationRule::EVENT_REOPENED,
            $case->status === CaseTicket::STATUS_IN_REVIEW => CaseTrackingNotificationRule::EVENT_IN_REVIEW,
            $case->status === CaseTicket::STATUS_IN_PROGRESS => CaseTrackingNotificationRule::EVENT_IN_PROGRESS,
            $case->status === CaseTicket::STATUS_RETURNED => CaseTrackingNotificationRule::EVENT_RETURNED,
            $case->status === CaseTicket::STATUS_RESOLVED => CaseTrackingNotificationRule::EVENT_RESOLVED,
            $case->status === CaseTicket::STATUS_CLOSED => CaseTrackingNotificationRule::EVENT_CLOSED,
            default => null,
        };

        if (! $eventKey) {
            return;
        }

        $title = match (true) {
            $eventKey === CaseTrackingNotificationRule::EVENT_REOPENED => 'Case Reopened',
            $case->status === CaseTicket::STATUS_SUBMITTED => 'Case Submitted',
            $case->status === CaseTicket::STATUS_IN_REVIEW => 'Case In Review',
            $case->status === CaseTicket::STATUS_IN_PROGRESS => 'Case In Progress',
            $case->status === CaseTicket::STATUS_RETURNED => 'Case Returned for Correction',
            $case->status === CaseTicket::STATUS_RESOLVED => 'Case Resolved',
            $case->status === CaseTicket::STATUS_CLOSED => 'Case Closed',
            default => 'Case Updated',
        };

        $message = $case->case_number . ' - ' . $case->subject . ' is now ' . $case->status . '.'
            . ($event->note ? ' ' . $event->note : '');

        CaseWorkflowNotifier::fire($eventKey, $case, $event->actorUserId, $title, $message, ['note' => (string) $event->note]);
    }
}
