<?php

namespace App\Events;

use App\Models\CaseTicket;

class CaseTicketAssigned
{
    public function __construct(public CaseTicket $caseTicket, public int $actorUserId, public ?int $previousAssigneeId = null)
    {
    }
}
