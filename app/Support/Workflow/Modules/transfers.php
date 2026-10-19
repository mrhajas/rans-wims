<?php

use App\Models\User;
use App\Support\Workflow\WorkflowModules as W;

$sms = fn (string $title) => "WIMS - Material Transfer {$title}\nTransfer: #reference\nFrom: #from\nTo: #to";

return [
    'key' => 'transfers', 'path' => 'transfers/workflow', 'page_route' => 'transfers.workflow', 'update_route' => 'transfers.workflow.update',
    'title' => 'Transfers Alerts', 'subtitle' => 'Decide who is told at each stage of a transfer, on which channels and with which words.',
    'record' => 'transfer', 'owner_label' => 'the person who created the transfer',
    'step_note' => 'A transfer leaving a Main Warehouse is held until someone with the approve-transfers permission approves it; that permission is set under Roles.',
    'events' => [
        'report' => W::ev('Transfer Email Report', 'The full email report (the items and who did it) of a completed transfer from or to a Main Warehouse. Choose who receives it; people need their email switched on in Recipients.', ['bell' => false, 'email' => true, 'icon' => 'email', 'subject' => '#title', 'body' => '#message']),
        'created' => W::ev('Transfer Created', 'A new transfer is saved (including a draft, or one that has to wait for approval).', ['bell' => false, 'owner' => true, 'icon' => 'plus', 'aliases' => ['nc:transfer-created']]),
        'updated' => W::ev('Transfer Edited', 'A transfer is edited and saved.', ['bell' => false, 'owner' => true, 'icon' => 'edit', 'aliases' => ['nc:transfer-updated']]),
        'deleted' => W::ev('Transfer Deleted', 'A transfer is deleted and its stock is put back.', ['roles' => ['Super Admin', 'Admin'], 'icon' => 'trash', 'aliases' => ['nc:transfer-deleted']]),
        'restored' => W::ev('Transfer Restored', 'A deleted transfer is brought back from the deleted records.', ['bell' => false, 'owner' => true, 'icon' => 'refresh', 'aliases' => ['nc:transfer-restored']]),
        'approval_needed' => W::ev('Transfer Waiting for Approval', 'A transfer leaving a Main Warehouse is submitted and has to be approved before stock moves.', ['permission' => 'approve-transfers', 'icon' => 'clock', 'aliases' => ['nc:transfer-approval-needed']]),
        'approved' => W::ev('Transfer Approved', 'A transfer waiting for approval is approved and the stock moves.', ['owner' => true, 'icon' => 'tick', 'aliases' => ['nc:transfer-approved']]),
        'cancelled' => W::ev('Transfer Cancelled', 'A transfer waiting for approval is cancelled (rejected) and nothing moves.', ['owner' => true, 'icon' => 'cross', 'aliases' => ['nc:transfer-cancelled']]),
        'dispatched' => W::ev('Transfer Dispatched', 'A transfer is finalised and on its way: the Site Engineers of the destination project are asked to confirm receipt.', ['roles' => User::SITE_ENGINEER_ROLES, 'project' => true, 'bell' => false, 'sms_text' => $sms('Dispatched') . "\nItems: #itemscount\nStatus: Dispatched\nPlease confirm receipt in WIMS.", 'icon' => 'truck', 'aliases' => ['wc:transfer-dispatched']]),
        'received' => W::ev('Transfer Received', 'The destination site confirms that the transfer arrived ("Mark as Received").', ['roles' => ['Super Admin', 'Admin'], 'bell' => false, 'sms_text' => $sms('Received') . "\nConfirmed by: #actor", 'icon' => 'in', 'aliases' => ['nc:transfer-received', 'wc:transfer-received']]),
        'done' => W::ev('Transfer Completed', 'A stock transfer between projects / warehouses is finalised (or a held transfer is approved) and the stock has moved.', ['roles' => ['Super Admin', 'Admin'], 'icon' => 'truck', 'aliases' => ['nc:transfer-done', 'em:transfer-completed']]),
        'permanently_deleted' => W::ev('Transfer Deleted for Good', 'A transfer is removed from the deleted records for good. When this is switched on it is used instead of "Transfer Deleted" for this step.', ['roles' => ['Super Admin', 'Admin'], 'enabled' => false, 'icon' => 'trash', 'subject' => 'Transfer deleted for good: #reference', 'body' => '#reference was deleted for good by #actor.', 'sms_text' => "WIMS - Transfer Deleted For Good\nTransfer: #reference\nBy: #actor"]),
        'delivery_note' => W::ev('Delivery Note Generated', 'A Delivery Note is generated from a transfer.', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'enabled' => false, 'icon' => 'doc-text', 'subject' => 'Delivery Note #document for #reference', 'body' => 'Delivery Note #document was generated from transfer #reference (#from to #to) by #actor.', 'sms_text' => "WIMS - Delivery Note Generated\nDelivery Note: #document\nTransfer: #reference"]),
        'gate_pass' => W::ev('Gate Pass Generated', 'A Gate Pass is generated from a transfer.', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'enabled' => false, 'icon' => 'doc', 'subject' => 'Gate Pass #document for #reference', 'body' => 'Gate Pass #document was generated from transfer #reference (#from to #to) by #actor.', 'sms_text' => "WIMS - Gate Pass Generated\nGate Pass: #document\nTransfer: #reference"]),
        'warehouse_cleanup' => W::ev('Warehouse Clean-up', 'An administrator cleans up a warehouse: its remaining stock is returned or distributed to other warehouses by new transfers.', ['roles' => ['Super Admin', 'Admin'], 'enabled' => false, 'icon' => 'refresh', 'subject' => 'Warehouse clean-up: #warehouse', 'body' => '#actor cleaned up #warehouse: #count transfer(s) were created.', 'sms_text' => "WIMS - Warehouse Clean-up\n#warehouse\n#count transfer(s) created by #actor."]),
    ],
    'tags' => [
        W::tag('document', 'Transfer', 'Document number', 'The Delivery Note or Gate Pass number (only for those two events).', 'GP-0012'),
        W::tag('warehouse', 'Clean-up', 'Warehouse cleaned up', 'The warehouse that was cleaned up.', 'MOMBASA MARKET'),
        W::tag('count', 'Clean-up', 'Transfers created', 'How many transfers the clean-up created.', '4'),
        W::tag('reference', 'Transfer', 'Transfer number', 'The transfer reference.', 'RST-0042'),
        W::tag('from', 'Transfer', 'From', 'The project / warehouse the stock leaves.', 'Main Store'),
        W::tag('to', 'Transfer', 'To', 'The project / warehouse the stock goes to.', 'MOMBASA MARKET'),
        W::tag('itemscount', 'Transfer', 'Number of items', 'How many items the transfer has.', '3'),
        W::tag('creator', 'Transfer', 'Created by', 'Who created the transfer.', 'Salha Shaaban'),
    ],
];
