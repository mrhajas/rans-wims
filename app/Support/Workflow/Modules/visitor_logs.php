<?php

use App\Support\Workflow\WorkflowModules as W;

// Visitor Logs: who is told when a visitor arrives or leaves, when a letter / package is received, is ready or is handed over, and when something has waited too long.
// Texts carry no link (an SMS provider may refuse them): the person opens WIMS to see the record. Confidential items never put their sender or subject in a message.
$sms = fn (string $title, string $body) => "WIMS - {$title}\n{$body}";

return [
    'key' => 'visitor_logs', 'path' => 'visitor-logs/workflow', 'page_route' => 'visitor-logs.workflow', 'update_route' => 'visitor-logs.workflow.update',
    'title' => 'Visitor Logs Alerts', 'subtitle' => 'Decide who is told when a visitor arrives or leaves, when a letter or package is received, ready or handed over, and when a visitor or an item has waited too long: which roles and people, on which channels, with which words.',
    'record' => 'visit', 'owner_label' => 'the person being visited (or the recipient of the item)', 'permission' => 'perm:manage-visitor-notifications',
    'step_note' => 'Add a role or a person to any event below to tell them as well, no code change needed. The person being visited, and the recipient of an item, are told when "the person" is ticked.',
    'events' => [
        'visitor_checked_in' => W::ev('Visitor Arrived', 'A visitor is checked in. The person being visited is told, and so are the roles and people added here (Super Admins by default).', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'in', 'bell' => true, 'sms' => true,
            'sms_text' => $sms('Visitor Arrival', "#who has arrived to see #host.\nPurpose: #purpose\nCheck-in: #checkin_time\nOpen WIMS to see the visit."), 'subject' => 'Visitor arrival: #visitor', 'body' => '#who has arrived to see #host. Purpose: #purpose. Check-in: #checkin_time. Reception: #location. Please open WIMS to view the visit details.']),
        'visitor_checked_out' => W::ev('Visitor Left', 'A visitor is checked out (off by default).', ['owner' => true, 'enabled' => false, 'icon' => 'out', 'sms_text' => $sms('Visitor Left', "#visitor has left.\nCheck-in: #checkin_time\nCheck-out: #checkout_time (#duration)"), 'subject' => 'Visitor left: #visitor', 'body' => '#visitor has left. Check-in: #checkin_time. Check-out: #checkout_time. Time on premises: #duration.']),
        'visit_long_stay' => W::ev('Visitor Still on Premises', 'A visitor has been checked in for longer than the hours set on the Visitor Logs settings. This is only a reminder: it does not mean the visitor is missing.', ['permission' => 'checkout-visitor-logs', 'roles' => ['Super Admin'], 'icon' => 'clock', 'sms_text' => $sms('Visitor still on premises', "#visitor (to see #host) has been checked in since #checkin_time (#duration)."), 'subject' => 'Visitor still on premises: #visitor', 'body' => '#visitor, to see #host, has been checked in since #checkin_time (#duration). Check whether they have left and check them out.']),
        'item_received' => W::ev('Item Received', 'A letter, package or document is received at reception. The recipient is told (a confidential item is announced without its sender or subject).', ['owner' => true, 'icon' => 'email', 'bell' => true, 'sms' => true,
            'sms_text' => $sms('Item received', "#item_line has arrived for you at reception.\nReceived: #received_time\nOpen WIMS to see the details."), 'subject' => 'Item received at reception: #item_ref', 'body' => '#item_line has arrived for you at reception on #received_time. Please open WIMS to view the details and arrange collection.']),
        'item_ready' => W::ev('Item Ready for Collection', 'An item is marked ready for collection.', ['owner' => true, 'icon' => 'tick', 'bell' => true, 'sms' => true, 'sms_text' => $sms('Ready for collection', "#item_line is ready for you to collect at reception."), 'subject' => 'Item ready for collection: #item_ref', 'body' => '#item_line is ready for collection at reception.']),
        'item_handed_over' => W::ev('Item Handed Over', 'An item is handed over to the recipient or an authorized collector (off by default).', ['owner' => true, 'enabled' => false, 'icon' => 'tick', 'sms_text' => $sms('Item handed over', "#item_line was handed to #handed_to."), 'subject' => 'Item handed over: #item_ref', 'body' => '#item_line was handed to #handed_to on #handed_time.']),
        'item_collection_overdue' => W::ev('Item Waiting Too Long', 'An item has waited for its recipient longer than the days set on the Visitor Logs settings.', ['owner' => true, 'permission' => 'update-incoming-item-status', 'icon' => 'clock', 'sms_text' => $sms('Item waiting', "#item_line has been waiting at reception since #received_time."), 'subject' => 'Item still waiting at reception: #item_ref', 'body' => '#item_line has been waiting at reception since #received_time. Please collect it or tell reception what to do with it.']),
    ],
    'tags' => [
        W::tag('visit_ref', 'Visit', 'Visit reference', 'The reference of the visit.', 'VIS-000123'),
        W::tag('visitor', 'Visit', 'Visitor', 'The name of the visitor.', 'Hamad Suleiman'),
        W::tag('company', 'Visit', 'Company', 'The company of the visitor (empty if none).', 'Zan Supplies Ltd'),
        W::tag('who', 'Visit', 'Visitor and company', 'The visitor, with the company when there is one.', 'Hamad Suleiman from Zan Supplies Ltd'),
        W::tag('host', 'Visit', 'Person visited', 'The person the visitor came to see.', 'Salim Iskiry'),
        W::tag('purpose', 'Visit', 'Purpose', 'The purpose of the visit.', 'Business meeting'),
        W::tag('checkin_time', 'Visit', 'Check-in time', 'When the visitor was checked in.', '22 Oct 2026, 09:12'),
        W::tag('checkout_time', 'Visit', 'Check-out time', 'When the visitor was checked out.', '22 Oct 2026, 10:40'),
        W::tag('duration', 'Visit', 'Time on premises', 'How long the visitor has been, or was, on the premises.', '1h 28m'),
        W::tag('project', 'Visit', 'Attended from', 'The project / warehouse the visit or the item is attended from.', 'HQ OFFICE'),
        W::tag('location', 'Visit', 'Reception location', 'The reception location set on the Visitor Logs settings.', 'Head Office, Fumba Road'),
        W::tag('item_ref', 'Incoming item', 'Item reference', 'The reference of the item.', 'INC-000045'),
        W::tag('item_line', 'Incoming item', 'The item', 'A short line about the item (without sender or subject when it is confidential).', 'A parcel from DHL'),
        W::tag('received_time', 'Incoming item', 'Received at', 'When the item was received.', '22 Oct 2026, 11:05'),
        W::tag('handed_to', 'Incoming item', 'Handed to', 'Who the item was handed to.', 'Asha Juma'),
        W::tag('handed_time', 'Incoming item', 'Handed over at', 'When the item was handed over.', '22 Oct 2026, 15:30'),
    ],
];
