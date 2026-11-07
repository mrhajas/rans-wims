<?php

use App\Support\Workflow\WorkflowModules as W;

return [
    'key' => 'checkins', 'path' => 'checkins/workflow', 'page_route' => 'checkins.workflow', 'update_route' => 'checkins.workflow.update',
    'title' => 'Check-ins Alerts', 'subtitle' => 'Decide who is told about check-ins, on which channels and with which words.',
    'record' => 'check-in', 'owner_label' => 'the person who recorded the check-in',
    'events' => [
        'report' => W::ev('Check-in Email Report', 'The full email report (the items and who did it) of a check-in completed at a Main Warehouse. Choose who receives it; people need their email switched on in Recipients.', ['bell' => false, 'email' => true, 'icon' => 'email', 'subject' => '#title', 'body' => '#message']),
        'main_done' => W::ev('Main Warehouse Check-in Completed', 'Stock is checked into a warehouse whose status is Main Warehouse and the check-in is finalised.', ['roles' => ['Super Admin', 'Admin'], 'sms_text' => "WIMS - Main Warehouse Checkin Completed\nReference: #reference\nWarehouse: #warehouse\nItems: #itemscount", 'icon' => 'in', 'aliases' => ['wc:checkin-main-warehouse', 'em:checkin-completed']]),
        'pending_approval' => W::ev('Check-in Pending Approval', 'A check-in is submitted and waits for approval. Its stock is not added until it is approved.', ['roles' => ['Super Admin'], 'icon' => 'clock', 'subject' => 'Check-in waiting for approval: #reference', 'body' => '#reference for #warehouse was submitted by #actor and waits for your approval.', 'sms_text' => "WIMS - Check-in Pending Approval\nReference: #reference\nWarehouse: #warehouse\nItems: #itemscount"]),
        'approved' => W::ev('Check-in Approved', 'A check-in is approved: its stock is added and its Goods Received Note (GRN) is generated.', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'tick', 'subject' => 'Check-in approved: #reference', 'body' => '#reference for #warehouse was approved by #actor. Goods Received Note: #document.', 'sms_text' => "WIMS - Check-in Approved\nReference: #reference\nGRN: #document\nWarehouse: #warehouse"]),
        'rejected' => W::ev('Check-in Rejected', 'A check-in is rejected, with the reason. No stock is added.', ['owner' => true, 'icon' => 'cross', 'subject' => 'Check-in rejected: #reference', 'body' => '#reference for #warehouse was rejected by #actor.', 'sms_text' => "WIMS - Check-in Rejected\nReference: #reference\nWarehouse: #warehouse"]),
        'deleted' => W::ev('Check-in Deleted', 'A check-in is deleted and its stock is taken back out.', ['roles' => ['Super Admin', 'Admin'], 'icon' => 'trash']),
        'created' => W::ev('Check-in Created', 'A check-in is saved at any warehouse (including a draft).', ['roles' => ['Super Admin', 'Admin'], 'owner' => false, 'enabled' => false, 'icon' => 'plus', 'subject' => 'Check-in created: #reference', 'body' => '#reference was created for #warehouse by #actor.', 'sms_text' => "WIMS - Check-in Created\nReference: #reference\nWarehouse: #warehouse\nItems: #itemscount"]),
        'updated' => W::ev('Check-in Edited', 'A check-in is edited and saved (its stock is recalculated).', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'enabled' => false, 'icon' => 'edit', 'subject' => 'Check-in edited: #reference', 'body' => '#reference for #warehouse was edited by #actor.', 'sms_text' => "WIMS - Check-in Edited\nReference: #reference\nWarehouse: #warehouse\nBy: #actor"]),
        'restored' => W::ev('Check-in Restored', 'A deleted check-in is brought back from the deleted records and its stock is put in again.', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'enabled' => false, 'icon' => 'refresh', 'subject' => 'Check-in restored: #reference', 'body' => '#reference for #warehouse was restored by #actor.', 'sms_text' => "WIMS - Check-in Restored\nReference: #reference\nWarehouse: #warehouse\nBy: #actor"]),
        'permanently_deleted' => W::ev('Check-in Deleted for Good', 'A check-in is removed from the deleted records for good. When this is switched on it is used instead of "Check-in Deleted" for this step.', ['roles' => ['Super Admin', 'Admin'], 'enabled' => false, 'icon' => 'trash', 'subject' => 'Check-in deleted for good: #reference', 'body' => '#reference was deleted for good by #actor.', 'sms_text' => "WIMS - Check-in Deleted For Good\nReference: #reference\nBy: #actor"]),
        'delivery_note' => W::ev('Delivery Note Generated', 'A Delivery Note is generated from a check-in.', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'enabled' => false, 'icon' => 'doc-text', 'subject' => 'Delivery Note #document for #reference', 'body' => 'Delivery Note #document was generated from check-in #reference by #actor.', 'sms_text' => "WIMS - Delivery Note Generated\nDelivery Note: #document\nCheck-in: #reference"]),
        'gate_pass' => W::ev('Gate Pass Generated', 'A Gate Pass is generated from a check-in.', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'enabled' => false, 'icon' => 'doc', 'subject' => 'Gate Pass #document for #reference', 'body' => 'Gate Pass #document was generated from check-in #reference by #actor.', 'sms_text' => "WIMS - Gate Pass Generated\nGate Pass: #document\nCheck-in: #reference"]),
    ],
    'tags' => [
        W::tag('document', 'Check-in', 'Document number', 'The Delivery Note, Gate Pass or Goods Received Note (GRN) number (only for those events).', 'GP-0012'),
        W::tag('reference', 'Check-in', 'Check-in number', 'The check-in reference.', 'RSC-0042'),
        W::tag('warehouse', 'Check-in', 'Project / Warehouse', 'The warehouse the stock was checked into.', 'Main Store'),
        W::tag('itemscount', 'Check-in', 'Number of items', 'How many items the check-in has.', '5'),
    ],
];
