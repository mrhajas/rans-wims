<?php

namespace App\Listeners;

use App\Events\CaseCommentAdded;
use App\Services\CaseWorkflowNotifier;
use Illuminate\Support\Str;

/** Who is told, on which channels and with which words is set on the Case Tracking workflow page (event "Case Comment Added"). */
class CaseCommentAddedListener
{
    public function handle(CaseCommentAdded $event): void
    {
        $case = $event->caseTicket;
        $preview = Str::limit($event->comment->body, 80);

        CaseWorkflowNotifier::fire('commented', $case, $event->actorUserId, 'New Comment on Case', $case->case_number . ' - ' . $event->comment->user?->name . ': "' . $preview . '"', ['comment' => $preview]);
    }
}
