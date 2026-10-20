<?php

use App\Support\Workflow\WorkflowModules as W;

// Administration: the weekly document in three stages (Requested, Approved, Authorized). These alerts say who is told about each step and how.
$sms = fn (string $title) => "WIMS - Administration {$title}\nRef: #reference\nDate: #date\nTotal: #amount";

return [
    'key' => 'administration', 'path' => 'weekly-administration/workflow', 'page_route' => 'weekly-administration.workflow', 'update_route' => 'weekly-administration.workflow.update',
    'title' => 'Administration Alerts', 'subtitle' => 'Decide who is told when a weekly Administration document is requested, approved, authorized, rejected, cancelled or sent, on which channels and with which words.',
    'record' => 'weekly administration document', 'owner_label' => 'the person who made the document and the beneficiary',
    'step_note' => 'People with the approve-weekly-administration permission (set under Roles) are told about requests; people with authorize-weekly-administration are told about approved documents.',
    'events' => [
        'requested' => W::ev('Waiting for Approval', 'A weekly document is made and needs approval (stage 2).', ['permission' => 'approve-weekly-administration', 'icon' => 'clock', 'sms_text' => $sms('waiting for approval'), 'subject' => 'Administration #reference is waiting for approval', 'body' => '#actor requested the Administration document #reference of #date for #amount.']),
        'approved' => W::ev('Approved, Waiting for Authorization', 'A document is approved and now waits for authorization (stage 3).', ['permission' => 'authorize-weekly-administration', 'owner' => true, 'icon' => 'tick', 'sms_text' => $sms('approved'), 'subject' => 'Administration #reference approved', 'body' => '#actor approved the Administration document #reference of #date for #amount. It now waits for authorization.']),
        'authorized' => W::ev('Authorized', 'A document is authorized: the last stage is done.', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'tick', 'sms_text' => $sms('authorized'), 'subject' => 'Administration #reference authorized', 'body' => '#actor authorized the Administration document #reference of #date for #amount.']),
        'rejected' => W::ev('Rejected', 'A document is rejected at approval or at authorization.', ['owner' => true, 'icon' => 'cross', 'sms_text' => $sms('rejected'), 'subject' => 'Administration #reference rejected', 'body' => '#actor rejected the Administration document #reference. #message']),
        'cancelled' => W::ev('Cancelled', 'A document is cancelled.', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'cross', 'sms_text' => $sms('cancelled'), 'subject' => 'Administration #reference cancelled', 'body' => '#actor cancelled the Administration document #reference. #message']),
        'sent' => W::ev('Document Sent', 'A document is sent by email to chosen people.', ['roles' => ['Super Admin'], 'icon' => 'email', 'subject' => 'Administration #reference was sent', 'body' => '#actor sent the Administration document #reference by email. #message']),
        'duplicated' => W::ev('Document Duplicated', 'A document is duplicated into a new one for the next week.', ['roles' => ['Super Admin'], 'icon' => 'duplicate', 'subject' => 'Administration #reference was duplicated', 'body' => '#actor duplicated an Administration document into #reference (#date).']),
    ],
    'tags' => [
        W::tag('reference', 'Document', 'Reference', 'The document reference.', 'ADM-00012'),
        W::tag('date', 'Document', 'Date', 'The date of the document.', '15 Oct 2026'),
        W::tag('amount', 'Document', 'Total amount', 'The total with its currency.', 'TZS 4,829,000.00'),
        W::tag('beneficiary', 'Document', 'Requested by', 'The beneficiary (requested by).', 'Salim Haroub Salim'),
        W::tag('status', 'Document', 'Status', 'The stage of the document.', 'Approved'),
        W::tag('lines', 'Document', 'Number of lines', 'How many lines the document has.', '28'),
    ],
];
