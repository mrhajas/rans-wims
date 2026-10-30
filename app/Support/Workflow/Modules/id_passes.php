<?php

use App\Support\Workflow\WorkflowModules as W;

// RANS ID & Pass Management: who is told when an ID or pass is issued, needs approval, is approved or rejected, is about to expire or has expired, and when it is suspended, revoked or replaced.
// Texts carry no link (an SMS provider may refuse them); the person opens WIMS to see the record.
$sms = fn (string $title, string $body) => "WIMS - {$title}\n{$body}";

return [
    'key' => 'id_passes', 'path' => 'id-passes/workflow', 'page_route' => 'id-passes.workflow', 'update_route' => 'id-passes.workflow.update',
    'title' => 'RANS ID & Pass Alerts', 'subtitle' => 'Decide who is told when an ID or pass is issued, waits for approval, is approved or rejected, is about to expire or has expired, and when it is suspended, revoked or replaced: which roles and people, on which channels, with which words.',
    'record' => 'card', 'owner_label' => 'the person who issued the ID or pass', 'permission' => 'perm:manage-id-notifications',
    'step_note' => 'Add a role or a person to any event below to tell them as well, no code change needed. Minor edits and prints are never announced.',
    'events' => [
        'issued' => W::ev('ID or Pass Issued', 'A new ID or pass is issued (and active).', ['owner' => false, 'roles' => ['Super Admin'], 'icon' => 'plus', 'bell' => true, 'enabled' => true,
            'sms_text' => $sms('ID issued', "#kind #number was issued to #holder.\nValid until: #expires"), 'subject' => '#kind issued: #number', 'body' => '#kind #number was issued to #holder (#category). Valid until: #expires.']),
        'approval_needed' => W::ev('Gate Pass Waiting for Approval', 'A pass that needs approval is waiting for someone with the approval permission.', ['permission' => 'approve-people-gate-passes', 'roles' => ['Super Admin'], 'icon' => 'clock', 'bell' => true, 'sms' => true,
            'sms_text' => $sms('Approval needed', "#kind #number for #holder is waiting for approval."), 'subject' => '#kind waiting for approval: #number', 'body' => '#kind #number for #holder (#category) is waiting for your approval. Reason: #purpose.']),
        'approved' => W::ev('Gate Pass Approved', 'A pass is approved; the person who asked for it is told.', ['owner' => true, 'roles' => [], 'icon' => 'tick', 'bell' => true,
            'sms_text' => $sms('Pass approved', "#kind #number for #holder was approved."), 'subject' => '#kind approved: #number', 'body' => '#kind #number for #holder was approved. Valid until: #expires.']),
        'rejected' => W::ev('Gate Pass Rejected', 'A pass is rejected, with the reason; the person who asked for it is told.', ['owner' => true, 'roles' => [], 'icon' => 'cross', 'bell' => true,
            'sms_text' => $sms('Pass rejected', "#kind #number for #holder was rejected."), 'subject' => '#kind rejected: #number', 'body' => '#kind #number for #holder was rejected. Reason: #reason.']),
        'expiring' => W::ev('ID or Pass Expiring Soon', 'An ID or pass will expire within the days set on the ID Settings (told once).', ['roles' => ['Super Admin'], 'icon' => 'clock', 'bell' => true,
            'sms_text' => $sms('ID expiring', "#kind #number of #holder expires on #expires."), 'subject' => '#kind expiring: #number', 'body' => '#kind #number of #holder (#category) expires on #expires. Please renew it or collect it.']),
        'expired' => W::ev('ID or Pass Expired', 'An ID or pass reached its expiry date.', ['roles' => ['Super Admin'], 'icon' => 'exclamation', 'bell' => true,
            'sms_text' => $sms('ID expired', "#kind #number of #holder has expired."), 'subject' => '#kind expired: #number', 'body' => '#kind #number of #holder (#category) expired on #expires.']),
        'suspended' => W::ev('ID or Pass Suspended', 'An ID or pass is suspended (it can be reactivated).', ['roles' => ['Super Admin'], 'icon' => 'pause', 'bell' => true,
            'sms_text' => $sms('ID suspended', "#kind #number of #holder was suspended."), 'subject' => '#kind suspended: #number', 'body' => '#kind #number of #holder (#category) was suspended. Reason: #reason.']),
        'revoked' => W::ev('ID or Pass Revoked', 'An ID or pass is revoked for good.', ['roles' => ['Super Admin'], 'icon' => 'x-circle', 'bell' => true, 'sms' => true,
            'sms_text' => $sms('ID revoked', "#kind #number of #holder was revoked."), 'subject' => '#kind revoked: #number', 'body' => '#kind #number of #holder (#category) was revoked. Reason: #reason.']),
        'replaced' => W::ev('ID Replaced', 'An ID or pass is replaced by a new one (lost, damaged or changed). The old one stops working.', ['roles' => ['Super Admin'], 'icon' => 'refresh', 'bell' => true, 'enabled' => false,
            'sms_text' => $sms('ID replaced', "#kind #number of #holder was replaced."), 'subject' => '#kind replaced: #number', 'body' => '#kind #number of #holder (#category) was replaced by a new one. Reason: #reason.']),
    ],
    'tags' => [
        W::tag('kind', 'ID', 'Kind', 'Staff ID, Compound Worker ID, Visitor Pass or Gate Pass.', 'Staff ID'),
        W::tag('number', 'ID', 'ID number', 'The number printed on the card.', 'RS001'),
        W::tag('holder', 'ID', 'Holder', 'The name of the person the card is for.', 'Amina Juma'),
        W::tag('category', 'ID', 'Category', 'The category of the card (Staff, Aluminium, Visitor ...).', 'Aluminium'),
        W::tag('expires', 'ID', 'Expiry date', 'The date the card stops being valid.', '15 May 2028'),
        W::tag('purpose', 'ID', 'Reason for access', 'The reason written on a gate pass.', 'Daily supplier deliveries'),
        W::tag('reason', 'ID', 'Reason', 'The reason given for a rejection, suspension, revocation or replacement.', 'Lost card'),
    ],
];
