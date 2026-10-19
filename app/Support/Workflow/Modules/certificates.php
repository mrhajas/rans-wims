<?php

use App\Support\Workflow\WorkflowModules as W;

$sms = fn (string $title, string $tail = '') => "WIMS - Certificate {$title}\nReference: #reference\nProject: #project\n" . $tail;

$new = ['roles' => ['Super Admin', 'Admin'], 'enabled' => false];

return [
    'key' => 'certificates', 'path' => 'project-certificates/workflow', 'page_route' => 'project-certificates.workflow', 'update_route' => 'project-certificates.workflow.update',
    'title' => 'Project Certificates Alerts', 'subtitle' => 'Choose who gets notified about certificates, and how.',
    'record' => 'certificate', 'permission' => 'perm:manage-settings-project-certificates',
    'owner_label' => 'the person who created the certificate',
    'step_note' => 'Nothing was sent for certificates before; switch notices on here. Whoever holds the permission for the next step (given on the Roles screen) can be told when it is their turn.',
    'events' => [
        'created' => W::ev('Certificate Created', 'A new certificate is saved as a draft.', ['bell' => false, 'sms_text' => $sms('Created', 'Created by: #actor'), 'subject' => 'Certificate #reference created', 'body' => 'Certificate #reference for #project was created by #actor.', 'icon' => 'doc-text']),
        'submitted' => W::ev('Certificate Submitted', 'A draft is submitted and waits for approval.', ['permission' => 'approve-project-certificates', 'step_owner' => false, 'bell' => false, 'sms_text' => $sms('Submitted', "Submitted by: #actor\nAwaiting approval."), 'subject' => 'Certificate #reference submitted', 'body' => 'Certificate #reference for #project was submitted by #actor and is waiting for approval.', 'icon' => 'clip']),
        'approved' => W::ev('Certificate Approved', 'A submitted certificate is approved and waits to be issued.', ['permission' => 'issue-project-certificates', 'step_owner' => false, 'bell' => false, 'sms_text' => $sms('Approved', "Approved by: #actor\nReady to issue."), 'subject' => 'Certificate #reference approved', 'body' => 'Certificate #reference for #project was approved by #actor and can now be issued.', 'icon' => 'tick']),
        'issued' => W::ev('Certificate Issued', 'An approved certificate is issued. It is then locked and payments can be recorded.', ['permission' => 'record-payment-project-certificates', 'step_owner' => false, 'bell' => false, 'sms_text' => $sms('Issued', "Issued by: #actor\nAmount: #amount"), 'subject' => 'Certificate #reference issued', 'body' => 'Certificate #reference for #project was issued by #actor. Amount: #amount.', 'icon' => 'tick']),
        'revised' => W::ev('Certificate Revised', 'An issued certificate is reopened as a new draft version.', ['bell' => false, 'sms_text' => $sms('Revised', 'Revised by: #actor'), 'subject' => 'Certificate #reference revised', 'body' => 'Certificate #reference for #project was revised by #actor.', 'icon' => 'refresh']),
        'cancelled' => W::ev('Certificate Cancelled', 'A certificate is cancelled.', ['bell' => false, 'sms_text' => $sms('Cancelled', "Cancelled by: #actor\n#remarks"), 'subject' => 'Certificate #reference cancelled', 'body' => "Certificate #reference for #project was cancelled by #actor.\n#remarks", 'icon' => 'trash']),
        'payment_recorded' => W::ev('Payment Recorded', 'A payment is recorded against an issued certificate.', ['bell' => false, 'sms_text' => $sms('Payment Recorded', "Recorded by: #actor\nAmount: #amount"), 'subject' => 'Payment recorded on certificate #reference', 'body' => 'A payment of #amount was recorded on certificate #reference for #project by #actor.', 'icon' => 'cash']),
        // new events: switched off until a Super Admin turns them on
        'updated' => W::ev('Certificate Edited', 'A draft certificate is edited. The person who created it is told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Certificate #reference edited', 'body' => 'Certificate #reference for #project was edited by #actor.', 'sms_text' => $sms('Edited', 'Edited by: #actor'), 'icon' => 'edit']),
        'calculation_saved' => W::ev('Certificate Calculation Saved', 'The calculation lines of a draft certificate are saved. New: off until switched on.', $new + ['subject' => 'Certificate #reference calculation saved', 'body' => 'The calculation of certificate #reference for #project was saved by #actor.', 'sms_text' => $sms('Calculation saved', 'By: #actor'), 'icon' => 'adjustments']),
        'payment_updated' => W::ev('Payment Corrected', 'A recorded payment of a certificate is corrected (Super Admin only). The person who created the certificate is told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Payment corrected on certificate #reference', 'body' => '#message', 'sms_text' => $sms('Payment corrected', 'By: #actor'), 'icon' => 'edit']),
        'payment_status_overridden' => W::ev('Payment Status Overridden', 'The payment status of a certificate is set by hand or cleared (Super Admin only). New: off until switched on.', $new + ['subject' => 'Payment status overridden on certificate #reference', 'body' => '#message', 'sms_text' => $sms('Payment status overridden', 'By: #actor'), 'icon' => 'exclamation']),
        'contract_saved' => W::ev('Project Contract Saved', 'The contract details of a project (clients, VAT, dates, variation) are saved. New: off until switched on.', $new + ['subject' => 'Contract saved: #project', 'body' => '#message', 'sms_text' => "WIMS - Contract saved\nProject: #project\nBy: #actor", 'icon' => 'doc-text']),
        'addendum_created' => W::ev('Contract Addendum Created', 'A contract addendum is added to a project. New: off until switched on.', $new + ['subject' => 'Addendum #reference created: #project', 'body' => '#message', 'sms_text' => "WIMS - Addendum created\n#reference, #project\nBy: #actor", 'icon' => 'plus']),
        'addendum_updated' => W::ev('Contract Addendum Edited', 'A contract addendum is edited. The person who created it is told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Addendum #reference edited: #project', 'body' => '#message', 'sms_text' => "WIMS - Addendum edited\n#reference, #project\nBy: #actor", 'icon' => 'edit']),
        'addendum_status_changed' => W::ev('Contract Addendum Status Changed', 'An addendum goes to Pending Approval, Approved, Rejected or back to Draft. The person who created it is told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Addendum #reference is now #status', 'body' => '#message', 'sms_text' => "WIMS - Addendum #status\n#reference, #project\nBy: #actor", 'icon' => 'refresh']),
        'addendum_deleted' => W::ev('Contract Addendum Deleted', 'A draft addendum is deleted. The person who created it is told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Addendum #reference deleted: #project', 'body' => '#message', 'sms_text' => "WIMS - Addendum deleted\n#reference, #project\nBy: #actor", 'icon' => 'trash']),
    ],
    'tags' => [
        W::tag('reference', 'Certificate', 'Certificate number', 'The certificate reference.', 'MOM-PC-012'),
        W::tag('project', 'Certificate', 'Project / Warehouse', 'The project the certificate is for.', 'MOMBASA MARKET'),
        W::tag('status', 'Certificate', 'Current status', 'Where the certificate is now.', 'Approved'),
        W::tag('type', 'Certificate', 'Certificate type', 'The type of certificate.', 'Interim'),
        W::tag('amount', 'Certificate', 'Net payment amount', 'The net amount of the certificate (or of the payment, for Payment Recorded).', 'TZS 18,400,000'),
        W::tag('period', 'Certificate', 'Period covered', 'The period the certificate covers.', 'September 2026'),
        W::tag('creator', 'Certificate', 'Created by', 'Who created the certificate.', 'Salha Shaaban'),
    ],
];
