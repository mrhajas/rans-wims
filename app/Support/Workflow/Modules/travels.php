<?php

use App\Support\Workflow\WorkflowModules as W;

// Travels: the travels of staff and users, approval based, and what ticket agents are paid. These alerts say who is told about each step and how.
$sms = fn (string $title) => "WIMS - Travel {$title}\nRef: #reference\nTraveller(s): #travellers\nTo: #destination on #depart_date";

return [
    'key' => 'travels', 'path' => 'travels/workflow', 'page_route' => 'travels.workflow', 'update_route' => 'travels.workflow.update',
    'title' => 'Travels Alerts', 'subtitle' => 'Decide who is told when a travel is recorded, approved, cancelled or about to leave, and when a ticket agent is paid, on which channels and with which words.',
    'record' => 'travel', 'owner_label' => 'the person who recorded the travel and the people travelling',
    'step_note' => 'People with the approve-travels permission (set under Roles) approve travels and are told about the ones waiting for approval.',
    'events' => [
        'approval_needed' => W::ev('Waiting for Approval', 'A travel is recorded and needs approval before the ticket agent is owed for it.', ['permission' => 'approve-travels', 'icon' => 'clock', 'sms_text' => $sms('waiting for approval'), 'subject' => 'Travel #reference is waiting for approval', 'body' => '#actor recorded a travel for #travellers to #destination on #depart_date (#amount). It needs approval.']),
        'approved' => W::ev('Travel Approved', 'A travel is approved: the ticket agent and the visa issuer are owed for it.', ['owner' => true, 'icon' => 'tick', 'sms_text' => $sms('approved'), 'subject' => 'Travel #reference approved', 'body' => '#actor approved the travel of #travellers to #destination on #depart_date.']),
        'rejected' => W::ev('Travel Rejected', 'A travel is rejected: nothing is owed.', ['owner' => true, 'icon' => 'cross', 'sms_text' => $sms('rejected'), 'subject' => 'Travel #reference rejected', 'body' => '#actor rejected the travel of #travellers to #destination. #message']),
        'cancelled' => W::ev('Travel Cancelled', 'A travel is cancelled (after approval this takes it out of what the agent is owed).', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'cross', 'sms_text' => $sms('cancelled'), 'subject' => 'Travel #reference cancelled', 'body' => '#actor cancelled the travel of #travellers to #destination. #message']),
        'departing_soon' => W::ev('Departing Soon', 'An approved travel leaves in a few days (the days are set on the Travels settings page).', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'clock', 'sms_text' => "WIMS - Travel reminder\nRef: #reference\n#travellers leave for #destination on #depart_date (in #days day(s)).\nFlight: #flight", 'subject' => 'Travel #reference leaves in #days day(s)', 'body' => '#travellers leave for #destination on #depart_date, in #days day(s). Flight: #flight. Ticket: #agent.']),
        'payment_requested' => W::ev('Agent Payment Requested', 'A Payment Voucher is started for a ticket agent from their balance (it then waits for approval in the Payment Vouchers module).', ['roles' => ['Super Admin'], 'permission' => 'read-travel-agents', 'icon' => 'cash', 'sms_text' => "WIMS - Travel agent payment requested\nRef: #payment\nAgent: #agent\nAmount: #amount", 'subject' => 'Payment #payment to #agent requested', 'body' => '#actor started Payment Voucher #voucher for #amount to #agent. #message']),
        'payment_paid' => W::ev('Agent Payment Paid', 'The Payment Voucher of a ticket agent is marked paid: it now reduces what the agent is owed.', ['roles' => ['Super Admin'], 'permission' => 'read-travel-agents', 'icon' => 'tick', 'sms_text' => "WIMS - Travel agent paid\nRef: #payment\nAgent: #agent\nAmount: #amount", 'subject' => 'Payment #payment to #agent paid', 'body' => 'Payment Voucher #voucher of #amount to #agent was paid (#method).']),
        'payment_released' => W::ev('Agent Payment Released', 'The Payment Voucher of a ticket agent was rejected, cancelled or deleted: nothing was paid and the amount is available again.', ['roles' => ['Super Admin'], 'permission' => 'read-travel-agents', 'icon' => 'cross', 'subject' => 'Payment #payment to #agent released', 'body' => 'Payment Voucher #voucher of #amount to #agent was not paid. #message']),
    ],
    'tags' => [
        W::tag('reference', 'Travel', 'Reference', 'The travel reference.', 'TRV-0012'),
        W::tag('travellers', 'Travel', 'Traveller(s)', 'The people travelling.', 'Asha Juma, Omar Ali'),
        W::tag('destination', 'Travel', 'Destination', 'Where they are going.', 'Dubai'),
        W::tag('depart_date', 'Travel', 'Departure date', 'The day they leave.', '12 Nov 2026'),
        W::tag('return_date', 'Travel', 'Return date', 'The day they come back.', '19 Nov 2026'),
        W::tag('flight', 'Travel', 'Flight', 'Airline and flight number.', 'Emirates EK 726'),
        W::tag('agent', 'Travel', 'Ticket agent', 'The ticket agent / visa issuer.', 'Zanzibar Travel Agency'),
        W::tag('amount', 'Travel', 'Amount', 'The amount with its currency.', 'USD 1,250.00'),
        W::tag('days', 'Travel', 'Days left', 'How many days until the travel.', '7'),
        W::tag('payment', 'Payment', 'Payment reference', 'The payment reference.', 'TPY-0004'),
        W::tag('voucher', 'Payment', 'Payment Voucher', 'The Payment Voucher number.', 'PV-2026-000123'),
        W::tag('method', 'Payment', 'Payment method', 'How it was paid.', 'Bank transfer'),
    ],
];
