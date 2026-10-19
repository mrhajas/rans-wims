<?php

use App\Support\Workflow\WorkflowModules as W;

// the text a case notice always had: "WIMS: <title>. <message>" (no link)
$sms = 'WIMS: #title. #message';
$status = fn (string $label, string $desc, string $icon, array $o = []) => W::ev($label, $desc, $o + ['owner' => true, 'sms_text' => $sms, 'icon' => $icon]);

$new = ['roles' => ['Super Admin', 'Admin'], 'enabled' => false, 'sms_text' => $sms];

return [
    'key' => 'case_tracking', 'path' => 'case-tracking/workflow', 'page_route' => 'case-tracking.workflow', 'update_route' => 'case-tracking.workflow.update',
    'title' => 'Case Tracking Alerts', 'subtitle' => 'Decide who is told as a case moves from Submitted to Closed, is assigned or commented on, on which channels and with which words.',
    'record' => 'case',
    // Case Tracking already limits by permission (View Own / Assigned Cases vs View All Cases); these rules narrow further, per role.
    'owner_label' => 'the case creator and the person it is assigned to (for Case Assigned: the person it was just assigned to)',
    'step_note' => 'The person who has just written a comment is never told about their own comment.',
    'events' => [
        'submitted' => $status('Case Submitted', 'A case is first submitted for review: the "someone needs to pick this up" notice.', 'doc-text', ['owner' => false, 'roles' => ['Super Admin', 'Admin']]),
        'in_review' => $status('Case In Review', 'A case moves into review.', 'search'),
        'in_progress' => $status('Case In Progress', 'Work starts on a case.', 'refresh'),
        'returned' => $status('Case Returned for Correction', 'A case is sent back to its creator for more information.', 'refresh'),
        'resolved' => $status('Case Resolved', 'A resolution is recorded on a case.', 'tick'),
        'reopened' => $status('Case Reopened', 'A Resolved or Closed case is reopened.', 'refresh'),
        'closed' => $status('Case Closed', 'A case is finally closed.', 'tick'),
        'assigned' => W::ev('Case Assigned / Reassigned', 'A case is assigned or reassigned: the person it was just assigned to is told (plus anyone added here). The older settings sent this one by email as well.', [
            'owner' => true, 'email' => true, 'subject' => '#title', 'body' => '#message', 'sms_text' => $sms, 'icon' => 'users',
        ]),
        'commented' => W::ev('Case Comment Added', 'Someone comments on a case: its creator and assignee are told (not the person who commented).', [
            'owner' => true, 'sms_text' => $sms, 'icon' => 'sms',
        ]),
        'deleted' => W::ev('Case Deleted', 'A case is deleted: its creator and assignee are told (not the person who deleted it). New: off until switched on.', [
            'owner' => true, 'enabled' => false, 'email' => true, 'sms_text' => $sms, 'icon' => 'trash',
        ]),
        // new events: switched off until a Super Admin turns them on
        'created' => W::ev('Case Created', 'A new case is saved (as a draft, or submitted straight away). New: off until switched on.', $new + ['icon' => 'plus', 'subject' => 'Case created: #casenumber', 'body' => '#message']),
        'updated' => W::ev('Case Edited', 'A case\'s details (subject, description, category, priority, project) are edited: its creator and assignee are told (not the person who edited it). New: off until switched on.', $new + ['owner' => true, 'icon' => 'edit', 'subject' => 'Case edited: #casenumber', 'body' => '#message']),
        'settings_changed' => W::ev('Case Settings Changed', 'A case category or priority is added, edited or deleted on Case Tracking > Settings. New: off until switched on.', $new + ['icon' => 'cog', 'subject' => 'Case Tracking settings changed', 'body' => '#message']),
    ],
    'tags' => [
        W::tag('casenumber', 'Case', 'Case number', 'The number of the case.', 'CASE-0042'),
        W::tag('subject', 'Case', 'Subject', 'The subject of the case.', 'Leaking roof at the store'),
        W::tag('status', 'Case', 'Status', 'Where the case is now.', 'In Review'),
        W::tag('creator', 'Case', 'Created by', 'Who created the case.', 'Salha Shaaban'),
        W::tag('assignee', 'Case', 'Assigned to', 'Who the case is assigned to.', 'Flow PM'),
        W::tag('note', 'Case', 'Note', 'The note typed with the status change (empty if none).', 'Needs a site visit'),
        W::tag('comment', 'Case', 'Comment', 'The first 80 characters of the comment.', 'Please send photos'),
    ],
];
