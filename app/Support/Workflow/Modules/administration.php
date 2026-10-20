<?php

use App\Support\Workflow\WorkflowModules as W;

// Administration: the weekly document in three stages (Requested, Approved, Authorized). These alerts say who is told about each step and how.
$sms = fn (string $title) => "WIMS - Administration {$title}\nRef: #reference\nDate: #date\nTotal: #amount";

return [
    'key' => 'administration', 'path' => 'weekly-administration/workflow', 'page_route' => 'weekly-administration.workflow', 'update_route' => 'weekly-administration.workflow.update',
    'title' => 'Administration Alerts', 'subtitle' => 'Decide who is told when a weekly Administration document is requested, approved, authorized, rejected, cancelled or sent, on which channels and with which words.',
    'record' => 'weekly administration document', 'owner_label' => 'the person who made the document and the beneficiary',
    'step_note' => 'People with the approve-weekly-administration permission (set under Roles) are told about requests; people with authorize-weekly-administration are told about approved documents.',
    'events' => [
        'requested' => W::ev('Waiting for Approval', 'A weekly document is made and needs approval (stage 2).', ['permission' => 'approve-weekly-administration', 'sms' => true, 'email' => true, 'icon' => 'clock', 'sms_text' => $sms('waiting for approval'), 'subject' => 'Administration #reference is waiting for approval', 'body' => '#actor requested the Administration document #reference of #date for #amount.']),
        'approved' => W::ev('Approved, Waiting for Authorization', 'A document is approved and now waits for authorization (stage 3).', ['permission' => 'authorize-weekly-administration', 'owner' => true, 'icon' => 'tick', 'sms_text' => $sms('approved'), 'subject' => 'Administration #reference approved', 'body' => '#actor approved the Administration document #reference of #date for #amount. It now waits for authorization.']),
        'authorized' => W::ev('Authorized', 'A document is authorized: the last stage is done.', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'tick', 'sms_text' => $sms('authorized'), 'subject' => 'Administration #reference authorized', 'body' => '#actor authorized the Administration document #reference of #date for #amount.']),
        'rejected' => W::ev('Rejected', 'A document is rejected at approval or at authorization.', ['owner' => true, 'icon' => 'cross', 'sms_text' => $sms('rejected'), 'subject' => 'Administration #reference rejected', 'body' => '#actor rejected the Administration document #reference. #message']),
        'cancelled' => W::ev('Cancelled', 'A document is cancelled.', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'cross', 'sms_text' => $sms('cancelled'), 'subject' => 'Administration #reference cancelled', 'body' => '#actor cancelled the Administration document #reference. #message']),
        'sent' => W::ev('Document Sent', 'A document is sent by email to chosen people.', ['roles' => ['Super Admin'], 'icon' => 'email', 'subject' => 'Administration #reference was sent', 'body' => '#actor sent the Administration document #reference by email. #message']),
        'duplicated' => W::ev('Document Duplicated', 'A document is duplicated into a new one for the next week.', ['roles' => ['Super Admin'], 'icon' => 'duplicate', 'subject' => 'Administration #reference was duplicated', 'body' => '#actor duplicated an Administration document into #reference (#date).']),
        'partially_paid' => W::ev('Partly Paid', 'Part of an authorized document is marked paid (some lines, or part of a line) and a balance is left.', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'cash', 'sms_text' => "WIMS - Administration partly paid\nRef: #reference\nPaid: #paid_amount to #paid_to\nBalance: #balance", 'subject' => 'Administration #reference partly paid', 'body' => '#actor marked #paid_amount of the Administration document #reference paid to #paid_to. Paid so far #paid_total, balance #balance.']),
        'fully_paid' => W::ev('Fully Paid', 'Everything on an authorized document is paid, by marking it paid or through its Payment Voucher.', ['owner' => true, 'roles' => ['Super Admin'], 'icon' => 'check-double', 'sms_text' => "WIMS - Administration fully paid\nRef: #reference\nPaid: #paid_total", 'subject' => 'Administration #reference fully paid', 'body' => 'The Administration document #reference is fully paid (#paid_total). The last payment was #paid_amount to #paid_to.']),
        'voucher_requested' => W::ev('Payment Voucher Made', 'A Payment Voucher is made for an authorized document (it then waits for approval in the Payment Vouchers module).', ['roles' => ['Super Admin'], 'permission' => 'mark-paid-weekly-administration', 'icon' => 'doc-text', 'sms_text' => "WIMS - Administration payment voucher\nRef: #reference\nVoucher: #voucher\nAmount: #paid_amount to #paid_to", 'subject' => 'Payment Voucher #voucher made for Administration #reference', 'body' => '#actor made Payment Voucher #voucher for #paid_amount to #paid_to from the Administration document #reference.']),
        'voucher_released' => W::ev('Payment Voucher Released', 'The Payment Voucher of an Administration payment was rejected, cancelled or deleted: nothing was paid and the lines can be paid again.', ['roles' => ['Super Admin'], 'owner' => true, 'icon' => 'cross', 'subject' => 'Payment for Administration #reference released', 'body' => 'The payment #payment of #paid_amount for #reference was released. #message']),
        'payment_reversed' => W::ev('Payment Reversed', 'A payment marked on a document is reversed.', ['roles' => ['Super Admin'], 'owner' => true, 'icon' => 'refresh', 'subject' => 'Payment on Administration #reference reversed', 'body' => '#actor reversed the payment #payment of #paid_amount to #paid_to on #reference. #message']),
    ],
    'tags' => [
        W::tag('reference', 'Document', 'Reference', 'The document reference.', 'ADM-00012'),
        W::tag('date', 'Document', 'Date', 'The date of the document.', '15 Oct 2026'),
        W::tag('amount', 'Document', 'Total amount', 'The total with its currency.', 'TZS 4,829,000.00'),
        W::tag('beneficiary', 'Document', 'Requested by', 'The beneficiary (requested by).', 'Salim Haroub Salim'),
        W::tag('status', 'Document', 'Status', 'The stage of the document.', 'Approved'),
        W::tag('lines', 'Document', 'Number of lines', 'How many lines the document has.', '28'),
        W::tag('paid_amount', 'Payment', 'Amount of this payment', 'What this payment paid, with the currency.', 'TZS 1,200,000.00'),
        W::tag('paid_total', 'Payment', 'Paid so far', 'Everything paid on the document so far.', 'TZS 2,000,000.00'),
        W::tag('balance', 'Payment', 'Balance', 'What is still to be paid.', 'TZS 1,959,000.00'),
        W::tag('paid_to', 'Payment', 'Paid to', 'The user or staff member who was paid.', 'Ali Nassor Salim'),
        W::tag('payment', 'Payment', 'Payment reference', 'The payment reference.', 'ADP-00004'),
        W::tag('voucher', 'Payment', 'Payment Voucher', 'The Payment Voucher number, when there is one.', 'PV-2026-000123'),
        W::tag('payment_status', 'Payment', 'Payment status', 'Unpaid, Partially Paid or Paid.', 'Partially Paid'),
        W::tag('method', 'Payment', 'Payment method', 'How it was paid.', 'Cash'),
    ],
];
