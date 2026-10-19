<?php

namespace App\Listeners;

use App\Events\CaseTicketAssigned;
use App\Services\CaseWorkflowNotifier;

/** Who is told, on which channels and with which words is set on the Case Tracking workflow page (event "Case Assigned / Reassigned"). */
class CaseTicketAssignedListener
{
    public function handle(CaseTicketAssigned $event): void
    {
        $case = $event->caseTicket;

        if (! $case->assigned_to_user_id) {
            return;
        }

        // Written in the third person since the audience can include more than the assignee once a Super Admin adds people on the workflow page.
        $verb = $event->previousAssigneeId ? 'reassigned to' : 'assigned to';

        CaseWorkflowNotifier::fire('assigned', $case, $event->actorUserId, 'Case Assigned', $case->case_number . ' - ' . $case->subject . ' has been ' . $verb . ' ' . $case->assignedTo?->name . '.');
    }
}
