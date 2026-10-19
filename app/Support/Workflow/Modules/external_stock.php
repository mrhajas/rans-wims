<?php

use App\Support\Workflow\WorkflowModules as W;

// External Stock: stock of External Personnel kept in our warehouses. Every movement is approval based; these alerts say who is told about it and how.
$sms = fn (string $title) => "WIMS - External Stock {$title}\nRef: #reference\nPerson: #person\nWarehouse: #warehouse";

return [
    'key' => 'external_stock', 'path' => 'external-stock/workflow', 'page_route' => 'external-stock.workflow', 'update_route' => 'external-stock.workflow.update',
    'title' => 'External Stock Alerts', 'subtitle' => 'Decide who is told when External Personnel stock is taken, brought or approved, on which channels and with which words.',
    'record' => 'external stock movement', 'owner_label' => 'the person who recorded the movement',
    'step_note' => 'People with the approve-external-stock permission (set under Roles) approve movements and are told about the ones waiting for approval.',
    'events' => [
        'approval_needed' => W::ev('Waiting for Approval', 'A movement is recorded and needs approval before any balance changes.', ['permission' => 'approve-external-stock', 'icon' => 'clock', 'sms_text' => $sms('waiting for approval'), 'subject' => 'External Stock #reference is waiting for approval', 'body' => '#actor recorded a #kind of #person at #warehouse. It needs approval.']),
        'approved' => W::ev('Movement Approved', 'A movement is approved and the balance of the person changes.', ['owner' => true, 'icon' => 'tick', 'sms_text' => $sms('approved'), 'subject' => 'External Stock #reference approved', 'body' => '#actor approved the #kind of #person at #warehouse.']),
        'rejected' => W::ev('Movement Rejected', 'A movement is rejected: nothing changes.', ['owner' => true, 'icon' => 'cross', 'sms_text' => $sms('rejected'), 'subject' => 'External Stock #reference rejected', 'body' => '#actor rejected the #kind of #person at #warehouse. #message']),
        'cancelled' => W::ev('Movement Cancelled', 'A movement is cancelled (after approval this takes it out of the balance again).', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'cross', 'subject' => 'External Stock #reference cancelled', 'body' => '#actor cancelled the #kind of #person at #warehouse. #message']),
        'withdrawn' => W::ev('Stock Taken Out', 'An approved Stock Out: External Personnel are taking stock out of a warehouse (driver, vehicle and collector are in the message).', ['roles' => ['Super Admin'], 'permission' => 'approve-external-stock', 'icon' => 'out', 'subject' => 'External Stock taken out: #reference', 'body' => '#person is taking stock out of #warehouse. Collected by #collector (#mobile), driver #driver, vehicle #vehicle.']),
    ],
    'tags' => [
        W::tag('reference', 'Movement', 'Reference', 'The movement reference.', 'EXS-0012'),
        W::tag('kind', 'Movement', 'Kind', 'Stock Out, Stock In or opening stock.', 'Stock Out'),
        W::tag('person', 'Movement', 'External person', 'The External Personnel contact.', 'Juma Builders'),
        W::tag('warehouse', 'Movement', 'Warehouse', 'The warehouse where the stock is kept.', 'Main Store'),
        W::tag('collector', 'Movement', 'Collected by', 'The person who collects or delivers.', 'Hassan Ali'),
        W::tag('mobile', 'Movement', 'Mobile', 'The collector mobile number.', '+255 712 345 678'),
        W::tag('driver', 'Movement', 'Driver', 'The driver.', 'Omar Khamis'),
        W::tag('vehicle', 'Movement', 'Vehicle', 'The vehicle registration.', 'T 123 ABC'),
    ],
];
