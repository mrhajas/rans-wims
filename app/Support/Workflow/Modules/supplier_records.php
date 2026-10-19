<?php

use App\Support\Workflow\WorkflowModules as W;

// Supplier Records sent no notifications before this page existed, so every event below is NEW and starts switched off: nothing is sent
// until a Super Admin switches an event on (the audience shown is only a suggestion to start from).
// Every other action of the module: one event each. All are NEW and start switched off. [key, label, what the event is, send to the record's owner too, step permission, icon]
$rows = [
    ['purchase_request_updated', 'Purchase Request Edited', 'A purchase request is edited.', true, null, 'edit'],
    ['purchase_request_deleted', 'Purchase Request Deleted', 'A purchase request is deleted.', true, null, 'trash'],
    ['quotation_created', 'Quotation Recorded', 'A new quotation is recorded.', false, null, 'plus'],
    ['quotation_updated', 'Quotation Edited', 'A quotation is edited.', true, null, 'edit'],
    ['quotation_deleted', 'Quotation Deleted', 'A quotation is deleted.', true, null, 'trash'],
    ['purchase_order_created', 'Purchase Order Created', 'A new purchase order is saved as a draft.', false, null, 'plus'],
    ['purchase_order_updated', 'Purchase Order Edited', 'A purchase order is edited.', true, null, 'edit'],
    ['purchase_order_deleted', 'Purchase Order Deleted', 'A purchase order is deleted.', true, null, 'trash'],
    ['purchase_order_submitted', 'Purchase Order Submitted', 'A purchase order is submitted for approval: everyone allowed to approve Purchase Orders is told.', false, 'approve-purchase-orders', 'clock'],
    ['purchase_order_approved', 'Purchase Order Approved', 'A purchase order is approved: the person who raised it is told.', true, null, 'tick'],
    ['purchase_order_rejected', 'Purchase Order Rejected', 'A purchase order is rejected, with the reason: the person who raised it is told.', true, null, 'cross'],
    ['purchase_order_sent', 'Purchase Order Sent To Supplier', 'A purchase order is marked as sent to the supplier: the person who raised it is told.', true, null, 'out'],
    ['purchase_order_acknowledged', 'Purchase Order Acknowledged', 'The supplier\'s acknowledgement of a purchase order is recorded: the person who raised it is told.', true, null, 'in'],
    ['purchase_order_cancelled', 'Purchase Order Cancelled', 'A purchase order is cancelled: the person who raised it is told.', true, null, 'trash'],
    ['goods_receipt_created', 'Goods Receipt Recorded', 'Goods received against a purchase order are recorded.', true, null, 'truck'],
    ['goods_receipt_updated', 'Goods Receipt Edited', 'A goods receipt is edited.', true, null, 'edit'],
    ['goods_receipt_deleted', 'Goods Receipt Deleted', 'A goods receipt is deleted (Super Admin only).', true, null, 'trash'],
    ['payment_request_updated', 'Payment Request Edited', 'A payment request is edited.', true, null, 'edit'],
    ['payment_request_deleted', 'Payment Request Deleted', 'A payment request is deleted.', true, null, 'trash'],
    ['payment_recorded', 'Supplier Payment Recorded', 'A payment to a supplier is recorded.', true, null, 'cash'],
    ['payment_verified', 'Supplier Payment Verified', 'A Super Admin verifies a supplier payment: the person who recorded it is told.', true, null, 'tick'],
    ['invoice_created', 'Supplier Invoice Recorded', 'A supplier invoice is recorded.', true, null, 'doc-text'],
    ['invoice_updated', 'Supplier Invoice Edited Or Reviewed', 'A supplier invoice is edited, approved or disputed.', true, null, 'edit'],
    ['invoice_deleted', 'Supplier Invoice Deleted', 'A supplier invoice is deleted.', true, null, 'trash'],
    ['supplier_details_updated', 'Supplier Details Updated', 'The financial details of a supplier (tax, terms, bank, credit limit) are changed.', false, null, 'edit'],
    ['ledger_entry_added', 'Ledger Entry Added', 'A manual entry is added to a supplier\'s ledger.', true, null, 'plus'],
    ['opening_balance_recorded', 'Opening Balance Recorded', 'An opening balance is recorded for a supplier and waits to be verified.', true, null, 'plus'],
    ['opening_balance_updated', 'Opening Balance Corrected', 'An opening balance entry is corrected.', true, null, 'edit'],
    ['opening_balance_deleted', 'Opening Balance Deleted', 'An opening balance entry is deleted.', true, null, 'trash'],
    ['opening_balance_verified', 'Opening Balance Verified', 'A Super Admin verifies and locks an opening balance: the person who recorded it is told.', true, null, 'tick'],
    ['reconciliation_created', 'Reconciliation Recorded', 'A supplier statement reconciliation is recorded.', true, null, 'doc-text'],
    ['reconciliation_resolved', 'Reconciliation Resolved', 'A reconciliation is reviewed and resolved: the person who recorded it is told.', true, null, 'tick'],
];
$extra = [];
foreach ($rows as [$k, $label, $desc, $owner, $perm, $icon]) {
    $extra[$k] = W::ev($label, $desc . ' New: off until switched on.', ['enabled' => false, 'icon' => $icon, 'owner' => $owner, 'permission' => $perm, 'roles' => $perm ? [] : ['Super Admin', 'Admin'],
        'subject' => $label . ': #reference', 'body' => '#message', 'sms_text' => "WIMS - {$label}\n#reference\nBy: #actor"]);
}

return [
    'key' => 'supplier_records', 'path' => 'supplier-records/workflow', 'page_route' => 'supplier-records.workflow', 'update_route' => 'supplier-records.workflow.update',
    'title' => 'Supplier Records Alerts', 'subtitle' => 'Decide who is told about purchase requests, payment requests and quotations, on which channels and with which words. Nothing is sent until you switch an event on.',
    'record' => 'supplier record', 'owner_label' => 'the person who raised the request (or recorded the quotation)',
 // the supplier chain starts with the purchase request, so the limit is on purchase requests
    'step_note' => 'A purchase request or a payment request waits for someone with the approve-purchase-requests / approve-payment-requests permission; those permissions are set under Roles.',
    'events' => [
        'purchase_request_submitted' => W::ev('Purchase Request Submitted', 'A purchase request is saved and waits for approval: everyone allowed to approve Purchase Requests is told.', ['enabled' => false, 'permission' => 'approve-purchase-requests', 'subject' => 'Purchase Request #reference submitted', 'body' => 'Purchase request #reference for #project was submitted by #actor and is waiting for your approval.', 'icon' => 'clock']),
        'purchase_request_approved' => W::ev('Purchase Request Approved', 'A purchase request is approved: the person who raised it is told.', ['enabled' => false, 'owner' => true, 'subject' => 'Purchase Request #reference approved', 'body' => "Purchase request #reference was approved by #actor.\n#remarks", 'icon' => 'tick']),
        'purchase_request_rejected' => W::ev('Purchase Request Rejected', 'A purchase request is rejected, with the remarks: the person who raised it is told.', ['enabled' => false, 'owner' => true, 'subject' => 'Purchase Request #reference rejected', 'body' => "Purchase request #reference was rejected by #actor.\n#remarks", 'icon' => 'cross']),
        'payment_request_submitted' => W::ev('Payment Request Submitted', 'A payment request for a supplier is saved and waits for approval: everyone allowed to approve Payment Requests is told.', ['enabled' => false, 'permission' => 'approve-payment-requests', 'subject' => 'Payment Request #reference submitted', 'body' => 'Payment request #reference for #supplier (#amount) was submitted by #actor and is waiting for your approval.', 'icon' => 'clock']),
        'payment_request_approved' => W::ev('Payment Request Approved', 'A payment request is approved: the person who raised it is told.', ['enabled' => false, 'owner' => true, 'subject' => 'Payment Request #reference approved', 'body' => "Payment request #reference for #supplier (#amount) was approved by #actor.\n#remarks", 'icon' => 'tick']),
        'payment_request_rejected' => W::ev('Payment Request Rejected', 'A payment request is rejected, with the remarks: the person who raised it is told.', ['enabled' => false, 'owner' => true, 'subject' => 'Payment Request #reference rejected', 'body' => "Payment request #reference for #supplier (#amount) was rejected by #actor.\n#remarks", 'icon' => 'cross']),
        'quotation_received' => W::ev('Quotation Received', 'A quotation is marked as received from the supplier: the person who recorded it is told.', ['enabled' => false, 'owner' => true, 'subject' => 'Quotation #reference received', 'body' => 'Quotation #reference from #supplier (#amount) was marked as received by #actor.', 'icon' => 'in']),
        'quotation_selected' => W::ev('Quotation Approved (Selected)', 'A quotation is selected as the winning one: the person who recorded it is told.', ['enabled' => false, 'owner' => true, 'subject' => 'Quotation #reference selected', 'body' => 'Quotation #reference from #supplier (#amount) was selected by #actor.', 'icon' => 'tick']),
        'quotation_rejected' => W::ev('Quotation Rejected', 'A quotation is rejected: the person who recorded it is told.', ['enabled' => false, 'owner' => true, 'subject' => 'Quotation #reference rejected', 'body' => 'Quotation #reference from #supplier (#amount) was rejected by #actor.', 'icon' => 'cross']),
    ] + $extra,
    'tags' => [
        W::tag('reference', 'Supplier record', 'Reference', 'The number of the purchase request, payment request or quotation.', 'PR-0042'),
        W::tag('supplier', 'Supplier record', 'Supplier', 'The supplier (payment requests and quotations).', 'Kilimanjaro Hardware'),
        W::tag('project', 'Supplier record', 'Project / Warehouse', 'The project or warehouse the record is for (when it has one).', 'MOMBASA MARKET'),
        W::tag('amount', 'Supplier record', 'Amount', 'The amount of the payment request, or the total of the quotation.', 'TZS 2,400,000'),
        W::tag('status', 'Supplier record', 'Current status', 'Where the record is now.', 'Approved'),
        W::tag('requester', 'Supplier record', 'Raised by', 'Who raised the request or recorded the quotation.', 'Salha Shaaban'),
    ],
];
