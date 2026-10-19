<?php

namespace App\Http\Controllers;

use App\Events\CaseCommentAdded;
use App\Models\CaseComment;
use App\Models\CaseTicket;
use Illuminate\Http\Request;

/**
 * Flat, append-only comment list — no edit/delete, matching this app's other
 * audit-style discussion trails (e.g. WorkPackageIssue's PemAuditLog) and the
 * module spec's own "simple and practical" brief. Every comment is also
 * mirrored into the activity timeline so History and Comments never disagree
 * about what was said and when.
 */
class CaseCommentController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function store(Request $request, CaseTicket $case_ticket)
    {
        $this->authorize('comment', $case_ticket);

        $data = $request->validate(['body' => 'required|string|max:3000']);

        $comment = CaseComment::create([
            'case_ticket_id' => $case_ticket->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        log_activity(
            __('Comment added to Case :number.', ['number' => $case_ticket->case_number]),
            ['comment_id' => $comment->id, 'ip' => $request->ip(), 'user_agent' => $request->userAgent()],
            $case_ticket,
            'Case Tracking'
        );

        event(new CaseCommentAdded($case_ticket, $comment, $request->user()->id));

        return back()->with('message', __('Comment added.'));
    }
}
