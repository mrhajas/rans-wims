<?php

use App\Support\Workflow\WorkflowModules as W;

return [
    'key' => 'checkouts', 'path' => 'checkouts/workflow', 'page_route' => 'checkouts.workflow', 'update_route' => 'checkouts.workflow.update',
    'title' => 'Check-outs Alerts', 'subtitle' => 'Decide who is told about check-outs, on which channels and with which words.',
    'record' => 'check-out', 'owner_label' => 'the person who recorded the check-out',
    'events' => [
        'report' => W::ev('Check-out Email Report', 'The full email report (the items and who did it) of a check-out completed at a Main Warehouse. Choose who receives it; people need their email switched on in Recipients.', ['bell' => false, 'email' => true, 'icon' => 'email', 'subject' => '#title', 'body' => '#message']),
        'main_done' => W::ev('Main Warehouse Check-out Completed', 'A check-out from a Main Warehouse is finalised.', ['roles' => ['Super Admin', 'Admin'], 'icon' => 'out', 'aliases' => ['em:checkout-completed']]),
        'main_anomaly' => W::ev('Check-out Recorded at a Main Warehouse', 'Material normally leaves a Main Warehouse only by Transfer, so a direct check-out is flagged as a process anomaly.', ['roles' => ['Super Admin'], 'bell' => false, 'sms_text' => "WIMS - Checkout at Main Warehouse (anomaly)\nReference: #reference\nWarehouse: #warehouse\nBy: #creator\nMaterial normally leaves a Main Warehouse via Transfer only.", 'icon' => 'exclamation', 'aliases' => ['wc:checkout-main-warehouse-anomaly']]),
        'deleted' => W::ev('Check-out Deleted', 'A check-out is deleted and its stock is put back.', ['roles' => ['Super Admin', 'Admin'], 'icon' => 'trash']),
        'created' => W::ev('Check-out Created', 'A check-out is saved at any warehouse (including a draft).', ['roles' => ['Super Admin', 'Admin'], 'owner' => false, 'enabled' => false, 'icon' => 'plus', 'subject' => 'Check-out created: #reference', 'body' => '#reference was created for #warehouse by #creator.', 'sms_text' => "WIMS - Check-out Created\nReference: #reference\nWarehouse: #warehouse\nBy: #creator"]),
        'updated' => W::ev('Check-out Edited', 'A check-out is edited and saved (its stock is recalculated).', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'enabled' => false, 'icon' => 'edit', 'subject' => 'Check-out edited: #reference', 'body' => '#reference for #warehouse was edited by #actor.', 'sms_text' => "WIMS - Check-out Edited\nReference: #reference\nWarehouse: #warehouse\nBy: #actor"]),
        'restored' => W::ev('Check-out Restored', 'A deleted check-out is brought back from the deleted records and its stock is taken out again.', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'enabled' => false, 'icon' => 'refresh', 'subject' => 'Check-out restored: #reference', 'body' => '#reference for #warehouse was restored by #actor.', 'sms_text' => "WIMS - Check-out Restored\nReference: #reference\nWarehouse: #warehouse\nBy: #actor"]),
        'permanently_deleted' => W::ev('Check-out Deleted for Good', 'A check-out is removed from the deleted records for good. When this is switched on it is used instead of "Check-out Deleted" for this step.', ['roles' => ['Super Admin', 'Admin'], 'enabled' => false, 'icon' => 'trash', 'subject' => 'Check-out deleted for good: #reference', 'body' => '#reference was deleted for good by #actor.', 'sms_text' => "WIMS - Check-out Deleted For Good\nReference: #reference\nBy: #actor"]),
    ],
    'tags' => [
        W::tag('reference', 'Check-out', 'Check-out number', 'The check-out reference.', 'RSO-0042'),
        W::tag('warehouse', 'Check-out', 'Project / Warehouse', 'The warehouse the stock left.', 'Main Store'),
        W::tag('creator', 'Check-out', 'Done by', 'Who recorded the check-out.', 'Salim Haroub'),
    ],
];
