<?php

use App\Support\Workflow\WorkflowModules as W;

$pm = ['Project Manager', 'Project Managers', 'Super Admin', 'Admin'];
$proc = ['Procurement Manager', 'Procurement Manger', 'Super Admin', 'Admin'];

return [
    'key' => 'reallocations', 'path' => 'material-reallocations/workflow', 'page_route' => 'material-reallocations.workflow', 'update_route' => 'material-reallocations.workflow.update',
    'title' => 'Material Reallocation Alerts', 'subtitle' => 'Decide who is told at each stage of a material reallocation, on which channels and with which words.',
    'record' => 'reallocation', 'owner_label' => 'the Site Engineer who requested it',
    'step_note' => 'The stages are: draft, submitted, cleared by the Project Manager, allocated by Procurement, dispatched, received, and finally approved.',
    'events' => [
        'report_cleared' => W::ev('Reallocation Cleared Email Report', 'The full email report (items and the history of every step) when a material reallocation is cleared.', ['bell' => false, 'email' => true, 'icon' => 'email', 'subject' => '#title', 'body' => '#message']),
        'report_final_approved' => W::ev('Reallocation Final Approval Email Report', 'The full email report when a material reallocation gets its final approval: items with destination, received and variance, the chain of custody, the history, and the whole record as a PDF.', ['bell' => false, 'email' => true, 'icon' => 'email', 'subject' => '#title', 'body' => '#message']),
        'created' => W::ev('Draft Created', 'A reallocation is saved as a draft (not yet submitted).', ['roles' => $pm, 'icon' => 'plus', 'aliases' => ['nc:reallocation-created']]),
        'submitted' => W::ev('Submitted for Clearance', 'A reallocation is submitted and waits for Project Manager clearance.', ['roles' => $pm, 'sms_text' => "WIMS - Material Reallocation Submitted\nReference: #reference\nAwaiting your clearance.", 'icon' => 'clip', 'aliases' => ['wc:reallocation-pm-clearance']]),
        'cleared' => W::ev('Cleared by the Project Manager', 'A reallocation is cleared and is ready for warehouse allocation.', ['roles' => $proc, 'owner' => true, 'sms_text' => "WIMS - Material Reallocation Cleared\nReference: #reference\nReady for warehouse allocation.", 'icon' => 'tick', 'aliases' => ['wc:reallocation-allocation-needed', 'em:material-reallocation-stage-change']]),
        'allocated' => W::ev('Warehouse Allocated', 'Procurement allocates the destination warehouse(s); the requester arranges dispatch.', ['roles' => $pm, 'owner' => true, 'sms_text' => "WIMS - Warehouse Allocated\nReference: #reference\nPlease arrange dispatch.", 'icon' => 'truck', 'aliases' => ['wc:reallocation-dispatch-needed']]),
        'dispatched' => W::ev('Shipment Dispatched', 'The shipment is dispatched and waits for receiving.', ['roles' => array_merge(['Store Manager'], $pm), 'sms_text' => "WIMS - Shipment Dispatched\nReference: #reference\nAwaiting receipt.", 'icon' => 'truck', 'aliases' => ['wc:reallocation-receiving-needed']]),
        'received' => W::ev('Received at the Destination', 'Receiving is recorded and the reallocation waits for final approval.', ['roles' => $pm, 'owner' => true, 'sms_text' => "WIMS - Reallocation Received\nReference: #reference\nAwaiting final approval.", 'icon' => 'in', 'aliases' => ['wc:reallocation-final-approval-needed']]),
        'completed' => W::ev('Reallocation Completed', 'Final approval is given and the stock has moved.', ['roles' => $pm, 'owner' => true, 'sms_text' => "WIMS - Reallocation Completed\nReference: #reference\n#message", 'icon' => 'tick', 'aliases' => ['wc:reallocation-completed', 'em:material-reallocation-stage-change']]),
        'changes_requested' => W::ev('Changes Requested', 'The approver asks the requester to correct and resubmit.', ['owner' => true, 'sms_text' => "WIMS - Changes Requested\nReference: #reference\nRemarks: #remarks", 'icon' => 'exclamation', 'aliases' => ['wc:reallocation-changes-requested']]),
        'rejected' => W::ev('Reallocation Rejected', 'The reallocation is rejected, with the reason.', ['owner' => true, 'sms_text' => "WIMS - Reallocation Rejected\nReference: #reference\nRemarks: #remarks", 'icon' => 'cross', 'aliases' => ['wc:reallocation-rejected']]),
        'cancelled' => W::ev('Reallocation Cancelled', 'A reallocation is cancelled; the requester is told.', ['owner' => true, 'icon' => 'trash', 'aliases' => ['wc:reallocation-cancelled']]),
        'updated' => W::ev('Reallocation Edited', 'A reallocation is edited and saved (while it is a draft or has been sent back for changes).', ['owner' => true, 'roles' => $pm, 'enabled' => false, 'icon' => 'edit', 'subject' => 'Reallocation edited: #reference', 'body' => '#message', 'sms_text' => "WIMS - Reallocation Edited\nReference: #reference\nBy: #actor"]),
        'deleted' => W::ev('Reallocation Deleted', 'A reallocation is deleted.', ['owner' => true, 'roles' => $pm, 'enabled' => false, 'icon' => 'trash', 'subject' => 'Reallocation deleted: #reference', 'body' => '#message', 'sms_text' => "WIMS - Reallocation Deleted\nReference: #reference\nBy: #actor"]),
    ],
    'tags' => [
        W::tag('action', 'This action', 'What happened', 'Created, Updated, Approved, Rejected, Deleted or Restored.', 'Approved'),
        W::tag('reference', 'Reallocation', 'Reallocation number', 'The reallocation reference.', 'MRA-0042'),
        W::tag('project', 'Reallocation', 'Source project / warehouse', 'Where the material comes from.', 'MOMBASA MARKET'),
        W::tag('requester', 'Reallocation', 'Requested by', 'The Site Engineer who requested it.', 'Salha Shaaban'),
    ],
];
