<?php

namespace App\Events;

use App\Models\CaseTicket;

class CaseTicketStatusChanged
{
    public function __construct(
        public CaseTicket $caseTicket,
        public int $actorUserId,
        public ?string $note = null,
        public ?string $previousStatus = null,
    ) {
    }
}
