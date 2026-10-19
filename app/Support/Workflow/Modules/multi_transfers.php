<?php

use App\Support\Workflow\WorkflowModules as W;

// Multi Transfer: one request, several source warehouses, one destination. The ordinary Transfers alerts still fire for each transfer it creates;
// these are about the request as a whole.
$sms = fn (string $title) => "WIMS - Multi Transfer {$title}\nRequest: #reference\nTo: #to\nSources: #sources";

return [
    'key' => 'multi_transfers', 'path' => 'multi-transfers/workflow', 'page_route' => 'multi-transfers.workflow', 'update_route' => 'multi-transfers.workflow.update',
    'title' => 'Multi Transfer Alerts', 'subtitle' => 'Decide who is told when a Multi Transfer is created, approved, cancelled or received, on which channels and with which words.',
    'record' => 'multi transfer', 'owner_label' => 'the person who created the Multi Transfer',
    'step_note' => 'Every transfer of a Multi Transfer is held until it is approved. People with the approve-multi-transfers permission (set under Roles) approve them together; the ordinary approve-transfers permission still approves one transfer at a time.',
    'events' => [
        'created' => W::ev('Multi Transfer Created', 'A Multi Transfer is saved and its transfers (one for each source warehouse) are created.', ['bell' => false, 'owner' => true, 'icon' => 'plus', 'subject' => 'Multi Transfer #reference created', 'body' => '#actor created Multi Transfer #reference to #to from #sources (#transfers).']),
        'approval_needed' => W::ev('Multi Transfer Waiting for Approval', 'A Multi Transfer is submitted: all of its transfers wait for approval before any stock moves.', ['permission' => 'approve-multi-transfers', 'icon' => 'clock', 'sms_text' => $sms('Waiting for approval'), 'subject' => 'Multi Transfer #reference is waiting for approval', 'body' => '#actor submitted Multi Transfer #reference to #to. Sources: #sources.']),
        'partly_approved' => W::ev('Multi Transfer Partly Approved', 'Some of the transfers of a Multi Transfer are approved and others are still waiting (or could not be approved because of stock).', ['owner' => true, 'icon' => 'tick', 'subject' => 'Multi Transfer #reference partly approved', 'body' => '#message']),
        'approved' => W::ev('Multi Transfer Approved', 'Every transfer of a Multi Transfer is approved and the stock has moved.', ['owner' => true, 'icon' => 'tick', 'sms_text' => $sms('Approved'), 'subject' => 'Multi Transfer #reference approved', 'body' => 'Every transfer of Multi Transfer #reference was approved. Stock has moved to #to.']),
        'cancelled' => W::ev('Multi Transfer Cancelled', 'Every transfer of a Multi Transfer is cancelled and nothing moved.', ['owner' => true, 'icon' => 'cross', 'sms_text' => $sms('Cancelled'), 'subject' => 'Multi Transfer #reference cancelled', 'body' => 'Multi Transfer #reference was cancelled by #actor. Nothing moved.']),
        'received' => W::ev('Multi Transfer Received', 'The destination has received every transfer of a Multi Transfer.', ['roles' => ['Super Admin', 'Admin'], 'bell' => false, 'icon' => 'in', 'subject' => 'Multi Transfer #reference received', 'body' => 'Every transfer of Multi Transfer #reference has been received at #to.']),
    ],
    'tags' => [
        W::tag('reference', 'Multi Transfer', 'Request number', 'The Multi Transfer reference.', 'MTR-0007'),
        W::tag('to', 'Multi Transfer', 'To', 'The project / warehouse everything goes to.', 'MOMBASA MARKET'),
        W::tag('sources', 'Multi Transfer', 'Sources', 'The warehouses the items come from.', 'Main Store, Premier Tower'),
        W::tag('transfers', 'Multi Transfer', 'Transfers', 'The references of the transfers it created.', 'RST-0101, RST-0102'),
    ],
];
