<?php

namespace App\Events;

use App\Models\CaseComment;
use App\Models\CaseTicket;

class CaseCommentAdded
{
    public function __construct(public CaseTicket $caseTicket, public CaseComment $comment, public int $actorUserId)
    {
    }
}
