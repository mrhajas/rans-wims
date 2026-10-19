<?php

use App\Support\Workflow\WorkflowModules as W;

// Subcontractor Management: who is told about agreements, variations, payment requests and imports, and on which channel.
$sms = fn (string $title) => "WIMS - Subcontract {$title}\nRef: #reference\nSubcontractor: #subcontractor\nAmount: #amount";

return [
    'key' => 'subcontracts', 'path' => 'subcontracts/workflow', 'page_route' => 'subcontracts.workflow', 'update_route' => 'subcontracts.workflow.update',
    'title' => 'Subcontract Alert Workflow', 'subtitle' => 'Decide who is told about subcontract agreements, variations, payment requests, vouchers and imports, on which channels and with which words.',
    'record' => 'subcontract record', 'owner_label' => 'the person who created the record',
    'step_note' => 'People with the review and approve permissions (set under Roles) are told about the items waiting for them.',
    'events' => [
        'alert_raised' => W::ev('Serious Alert Raised', 'A high or critical alert was raised on the Alerts page: an account paid above its contract value, an approval above the limit, and similar.', ['permission' => 'resolve-alerts-subcontracts', 'icon' => 'exclamation', 'sms_text' => $sms('alert'), 'subject' => 'Subcontract alert: #message', 'body' => '#message']),
        'agreement_review' => W::ev('Agreement Waiting for Review', 'An agreement was submitted and needs review.', ['permission' => 'review-subcontract-agreements', 'icon' => 'clock', 'sms_text' => $sms('agreement waiting for review'), 'subject' => 'Subcontract agreement #reference needs review', 'body' => '#actor submitted the agreement with #subcontractor. It needs review.']),
        'agreement_decided' => W::ev('Agreement Approved or Rejected', 'An agreement was approved or rejected.', ['owner' => true, 'icon' => 'tick', 'sms_text' => $sms('agreement decided'), 'subject' => 'Subcontract agreement #reference: decision', 'body' => '#actor decided the agreement with #subcontractor. #message']),
        'variation_review' => W::ev('Variation Waiting for Approval', 'A variation was submitted and waits for review or approval.', ['permission' => 'approve-subcontract-variations', 'icon' => 'clock', 'sms_text' => $sms('variation waiting'), 'subject' => 'Subcontract variation #reference needs a decision', 'body' => '#actor submitted a variation of #amount for #subcontractor.']),
        'variation_decided' => W::ev('Variation Approved or Rejected', 'A variation was approved or rejected.', ['owner' => true, 'icon' => 'tick', 'sms_text' => $sms('variation decided'), 'subject' => 'Subcontract variation #reference: decision', 'body' => '#actor decided the variation for #subcontractor. #message']),
        'request_review' => W::ev('Payment Request Submitted', 'A payment request was submitted and needs review or approval.', ['permission' => 'approve-subcontract-requests', 'icon' => 'send', 'sms_text' => $sms('payment request submitted'), 'subject' => 'Subcontract payment request #reference needs a decision', 'body' => '#actor requested #amount for #subcontractor.']),
        'request_returned' => W::ev('Request Returned for Correction', 'A payment request was returned to the person who made it.', ['owner' => true, 'icon' => 'refresh', 'sms_text' => $sms('request returned'), 'subject' => 'Subcontract payment request #reference was returned', 'body' => '#actor returned the request. #message']),
        'request_decided' => W::ev('Request Approved or Rejected', 'A payment request was approved or rejected.', ['owner' => true, 'icon' => 'tick', 'sms_text' => $sms('request decided'), 'subject' => 'Subcontract payment request #reference: decision', 'body' => '#actor decided the request for #subcontractor. #message']),
        'voucher_started' => W::ev('Payment Voucher Started', 'A Payment Voucher was started for an approved request.', ['permission' => 'approve-payment-vouchers', 'icon' => 'doc-text', 'sms_text' => $sms('voucher started'), 'subject' => 'Payment voucher started for subcontract request #reference', 'body' => '#actor started a Payment Voucher of #amount for #subcontractor.']),
        'payment_paid' => W::ev('Payment Confirmed', 'The Payment Voucher of a subcontract payment was paid.', ['owner' => true, 'icon' => 'tick', 'sms_text' => $sms('payment confirmed'), 'subject' => 'Subcontract payment confirmed: #reference', 'body' => 'The payment of #amount to #subcontractor was paid.']),
        'claim_submitted' => W::ev('Contract Payment Request Waiting', 'A contract payment request (claim for several completed agreements) was submitted or a corrected line was sent again, and waits for a decision.', ['permission' => 'decide-subcontract-claims', 'icon' => 'send', 'sms_text' => $sms('payment request waiting for a decision'), 'subject' => 'Contract payment request #reference needs a decision', 'body' => '#actor asked for #amount for #subcontractor. #message']),
        'claim_returned' => W::ev('Contract Payment Request Returned', 'A line of a contract payment request was returned to the person who made it.', ['owner' => true, 'icon' => 'refresh', 'sms_text' => $sms('payment request returned'), 'subject' => 'Contract payment request #reference was returned', 'body' => '#actor returned a line of the request for #subcontractor. #message']),
        'claim_decided' => W::ev('Contract Payment Request Decided', 'Lines of a contract payment request were approved, rejected or deferred.', ['owner' => true, 'icon' => 'tick', 'sms_text' => $sms('payment request decided'), 'subject' => 'Contract payment request #reference: decision', 'body' => '#message']),
        'claim_voucher_started' => W::ev('Contract Payment Voucher Started', 'A Payment Voucher was started for approved lines of a contract payment request.', ['permission' => 'approve-payment-vouchers', 'icon' => 'doc-text', 'sms_text' => $sms('contract payment voucher started'), 'subject' => 'Payment voucher started for contract payment request #reference', 'body' => '#actor started a Payment Voucher of #amount for #subcontractor.']),
        'claim_paid' => W::ev('Contract Payment Confirmed', 'The Payment Voucher of a contract payment request was paid.', ['owner' => true, 'icon' => 'tick', 'sms_text' => $sms('contract payment confirmed'), 'subject' => 'Contract payment confirmed: #reference', 'body' => 'The payment of #amount to #subcontractor was paid.']),
        'limit_exceeded' => W::ev('Overpayment Attempt or Override', 'A request was approved above the remaining entitlement with an override, or an overpayment was refused.', ['roles' => ['Super Admin'], 'icon' => 'cross', 'subject' => 'Subcontract limit: #reference', 'body' => '#actor: #message']),
        'import_review' => W::ev('Import Needs Reconciliation', 'A staged import was confirmed and has differences to reconcile.', ['permission' => 'approve-reconciliation-subcontracts', 'icon' => 'clock', 'subject' => 'Subcontract import #reference needs reconciliation', 'body' => '#message']),
        'completion_due' => W::ev('Agreement Nearing Completion', 'An agreement reaches its expected completion date soon.', ['owner' => true, 'icon' => 'clock', 'subject' => 'Subcontract agreement #reference ends soon', 'body' => '#message']),
    ],
    'tags' => [
        W::tag('reference', 'Record', 'Reference', 'The record reference.', 'SPR-2026-0012'),
        W::tag('subcontractor', 'Record', 'Subcontractor', 'The subcontractor (Contact).', 'Eddy Aluminium'),
        W::tag('agreement', 'Record', 'Agreement', 'The agreement reference.', 'SCA-2026-0003'),
        W::tag('amount', 'Record', 'Amount', 'The amount of the record.', '12,500,000.00'),
    ],
];
