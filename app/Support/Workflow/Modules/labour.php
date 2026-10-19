<?php

use App\Support\Workflow\WorkflowModules as W;

$sms = fn (string $title, string $tail = '') => "WIMS - Labour List {$title}\nReference: #reference\nProject: #project\n" . $tail;

$new = ['roles' => ['Super Admin', 'Admin'], 'enabled' => false];

return [
    'key' => 'labour', 'path' => 'labour/workflow', 'page_route' => 'labour.workflow', 'update_route' => 'labour.workflow.update',
    'title' => 'Labour Lists Alerts', 'subtitle' => 'Choose who gets notified about labour lists, and how.',
    'record' => 'labour list',
    'owner_label' => 'the person who created the list',
    'step_note' => 'Whoever holds the permission for the next step in the chain (given on the Roles screen) is told when it is their turn.',
    'events' => [
        'submitted' => W::ev('Labour List Submitted', 'A list is submitted and waits for the Project Manager to clear it.', ['owner' => true, 'permission' => 'clear-labour', 'sms_text' => $sms('Submitted', "Submitted by: #actor\nAwaiting your clearance."), 'subject' => 'Labour List #reference submitted', 'body' => 'Labour list #reference for #project was submitted by #actor and is waiting for clearance.', 'icon' => 'doc-text']),
        'returned' => W::ev('Labour List Returned', 'A list is sent back for correction, with the reviewer\'s remarks.', ['owner' => true, 'permission' => 'submit-labour', 'sms_text' => $sms('Returned', "Returned by: #actor\nRemarks: #remarks"), 'subject' => 'Labour List #reference returned', 'body' => "Labour list #reference for #project was returned by #actor.\n#remarks", 'icon' => 'refresh']),
        'cleared' => W::ev('Labour List Cleared', 'The Project Manager clears a list and it waits for final approval.', ['owner' => true, 'permission' => 'approve-labour', 'sms_text' => $sms('Cleared', "Cleared by: #actor\nAwaiting your approval."), 'subject' => 'Labour List #reference cleared', 'body' => 'Labour list #reference for #project was cleared by #actor and is waiting for approval.', 'icon' => 'tick']),
        'approved' => W::ev('Labour List Approved', 'A list is approved for payment.', ['owner' => true, 'permission' => 'create-payment-voucher-from-labour', 'sms_text' => $sms('Approved', "Approved by: #actor\nReady for payment vouchers."), 'subject' => 'Labour List #reference approved', 'body' => 'Labour list #reference for #project was approved by #actor and can now become Payment Vouchers.', 'icon' => 'tick']),
        'payment_voucher_created' => W::ev('Payment Voucher Created', 'An approved list is turned into Payment Voucher(s).', ['owner' => true, 'permission' => 'submit-labour', 'sms_text' => $sms('Payment Voucher Created', 'By: #actor'), 'subject' => 'Labour List #reference: payment voucher created', 'body' => 'Labour list #reference for #project was converted into Payment Voucher(s) by #actor.', 'icon' => 'cash']),
        // new events: switched off until a Super Admin turns them on
        'created' => W::ev('Labour List Created', 'A new labour list is saved as a draft. New: off until switched on.', $new + ['subject' => 'Labour List #reference created', 'body' => 'Labour list #reference for #project was created by #actor.', 'sms_text' => $sms('Created', 'Created by: #actor'), 'icon' => 'plus']),
        'updated' => W::ev('Labour List Edited', 'A labour list is edited (a returned list becomes a draft again). The person who created it is told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Labour List #reference edited', 'body' => 'Labour list #reference for #project was edited by #actor.', 'sms_text' => $sms('Edited', 'Edited by: #actor'), 'icon' => 'edit']),
        'deleted' => W::ev('Labour List Deleted', 'A labour list is deleted. The person who created it is told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Labour List #reference deleted', 'body' => 'Labour list #reference for #project was deleted by #actor.', 'sms_text' => $sms('Deleted', 'Deleted by: #actor'), 'icon' => 'trash']),
    ],
    'tags' => [
        W::tag('reference', 'Labour list', 'List number', 'The labour list number.', 'LBR-0042'),
        W::tag('project', 'Labour list', 'Project / Warehouse', 'The project the list is for.', 'MOMBASA MARKET'),
        W::tag('status', 'Labour list', 'Current status', 'Where the list is now.', 'Cleared'),
        W::tag('period', 'Labour list', 'Period', 'The period the list covers.', '01 Oct 2026 to 07 Oct 2026'),
        W::tag('amount', 'Labour list', 'Total amount', 'The total amount of the list.', 'TZS 4,250,000'),
        W::tag('itemscount', 'Labour list', 'Number of lines', 'How many lines the list has.', '14'),
        W::tag('creator', 'Labour list', 'Created by', 'Who created the list.', 'Salha Shaaban'),
    ],
];
