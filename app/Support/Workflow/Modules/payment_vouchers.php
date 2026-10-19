<?php

use App\Support\Workflow\WorkflowModules as W;

// Payment Vouchers told people on the app bell only. A voucher is Submitted, Approved (stage 1), Authorised (stage 2), then paid; it can be Rejected or Cancelled.
// The people who approve / authorise are found by permission (approve-payment-vouchers, authorise-payment-vouchers), the preparer is the owner.
$new = ['roles' => ['Super Admin', 'Admin'], 'enabled' => false];

return [
    'key' => 'payment_vouchers', 'path' => 'payment-vouchers/workflow', 'page_route' => 'payment-vouchers.workflow', 'update_route' => 'payment-vouchers.workflow.update',
    'title' => 'Payment Vouchers Alerts', 'subtitle' => 'Decide who is told at each stage of a payment voucher, on which channels and with which words.',
    'record' => 'payment voucher', 'owner_label' => 'the person who prepared the voucher (for the paid-labour and paid-transport notices: the person who created the labour list / logged the trips)',
    'step_note' => 'A voucher is approved by someone with the approve-payment-vouchers permission (stage 1), authorised by someone with the authorise-payment-vouchers permission (stage 2) and then paid. The permissions are set under Roles.',
    'events' => [
        'submitted' => W::ev('Voucher Submitted for Approval', 'A voucher is submitted: everyone allowed to approve Payment Vouchers is told (not the person who prepared it).', ['permission' => 'approve-payment-vouchers', 'subject' => 'Payment Voucher Submitted for Approval', 'body' => 'Payment Voucher #voucher is awaiting your approval.', 'icon' => 'clock']),
        'approved' => W::ev('Voucher Approved', 'A voucher passes stage 1: everyone allowed to authorise Payment Vouchers is told (not the person who prepared it).', ['permission' => 'authorise-payment-vouchers', 'subject' => 'Payment Voucher Approved', 'body' => 'Payment Voucher #voucher has been approved and is awaiting your authorisation.', 'icon' => 'tick']),
        'authorised' => W::ev('Voucher Authorised', 'A voucher passes stage 2 and can be paid: the person who prepared it is told.', ['owner' => true, 'subject' => 'Payment Voucher Authorised', 'body' => 'Your Payment Voucher #voucher has been authorised.', 'icon' => 'tick']),
        'rejected' => W::ev('Voucher Rejected', 'A voucher is rejected at approval or authorisation, with the reason: the person who prepared it is told.', ['owner' => true, 'subject' => 'Payment Voucher Rejected', 'body' => '#message','icon' => 'cross']),
        'cancelled' => W::ev('Voucher Cancelled', 'A voucher is cancelled: the person who prepared it is told.', ['owner' => true, 'subject' => 'Payment Voucher Cancelled', 'body' => 'Your Payment Voucher #voucher has been cancelled.', 'icon' => 'trash']),
        'labour_list_paid' => W::ev('Labour List Fully Paid', 'The last Payment Voucher of a labour list is marked as paid: the person who created the labour list is told. The owner is the creator of the list; #reference is the labour list number.', ['owner' => true, 'subject' => 'Labour Payment Completed', 'body' => '#reference, all payment vouchers have now been paid.', 'icon' => 'cash']),
        'transport_trips_paid' => W::ev('Transport Trips Paid', 'A voucher linked to transport trips is marked as paid and the trips become Paid: whoever logged those trips is told. The owner is the person who logged the trips.', ['owner' => true, 'subject' => 'Transport Payment Completed', 'body' => '#reference has been paid, linked transport trips are now marked Paid.', 'icon' => 'car']),
        'created' => W::ev('Voucher Created', 'A new payment voucher is saved as a draft (new: not sent until you switch it on). The owner is the person who prepared the voucher.', $new + ['subject' => 'Payment Voucher #voucher created', 'body' => 'Payment Voucher #voucher for #payee (#amount) was created by #actor.', 'sms_text' => "WIMS - Voucher created\n#voucher, #payee\n#amount\nBy: #actor", 'icon' => 'plus']),
        'batch_created' => W::ev('Voucher Batch Created', 'Several vouchers are created together as a batch (new: not sent until you switch it on).', $new + ['subject' => 'Voucher batch #batch created', 'body' => 'Batch #batch with #count payment vouchers (#amount) was created by #actor.', 'sms_text' => "WIMS - Voucher batch created\n#batch: #count vouchers, #amount\nBy: #actor", 'icon' => 'plus']),
        'updated' => W::ev('Voucher Edited', 'A draft or rejected payment voucher is edited (new: not sent until you switch it on).', $new + ['owner' => true, 'subject' => 'Payment Voucher #voucher edited', 'body' => 'Payment Voucher #voucher for #payee (#amount) was edited by #actor.', 'sms_text' => "WIMS - Voucher edited\n#voucher, #payee\nBy: #actor", 'icon' => 'edit']),
        'deleted' => W::ev('Voucher Deleted', 'A draft payment voucher is deleted (new: not sent until you switch it on).', $new + ['owner' => true, 'subject' => 'Payment Voucher #voucher deleted', 'body' => 'Payment Voucher #voucher for #payee (#amount) was deleted by #actor.', 'sms_text' => "WIMS - Voucher deleted\n#voucher, #payee\nBy: #actor", 'icon' => 'trash']),
        'paid' => W::ev('Voucher Paid', 'The payment of an authorised voucher is recorded (new: not sent until you switch it on). The owner is the person who prepared the voucher.', ['owner' => true, 'enabled' => false, 'subject' => 'Payment Voucher Paid', 'body' => 'Payment Voucher #voucher has been paid by #actor.', 'icon' => 'cash']),
    ],
    'tags' => [
        W::tag('voucher', 'Payment voucher', 'Voucher number', 'The Payment Voucher number.', 'PV-0042'),
        W::tag('reference', 'Payment voucher', 'Reference', 'The number the notice is about: the voucher number (for the labour event, the labour list number).', 'PV-0042'),
        W::tag('payee', 'Payment voucher', 'Payee', 'Who is paid.', 'Hamisi Mussa'),
        W::tag('project', 'Payment voucher', 'Project / Warehouse', 'The project the voucher is for (the company name if none).', 'MOMBASA MARKET'),
        W::tag('amount', 'Payment voucher', 'Total amount', 'The total of the voucher.', 'TZS 1,250,000'),
        W::tag('status', 'Payment voucher', 'Current status', 'Where the voucher is now.', 'Approved'),
        W::tag('batch', 'Voucher batch', 'Batch number', 'The batch number (Voucher Batch Created).', 'PVB-0007'),
        W::tag('count', 'Voucher batch', 'Number of vouchers', 'How many vouchers the batch holds.', '12'),
        W::tag('preparer', 'Payment voucher', 'Prepared by', 'Who prepared the voucher.', 'Salha Shaaban'),
    ],
];
