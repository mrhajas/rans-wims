<?php

use App\Support\Workflow\WorkflowModules as W;

// Signatories: who is told when a signature is registered (so somebody verifies it), activated, suspended, revoked, or when a report needed a
// signature that was not available. The person the notice is about can be told too. Everything is off until a Super Admin switches it on,
// except the one that keeps the process moving: a new signature waiting for verification goes to people allowed to verify.
$sms = "WIMS - #title\n#message";

return [
    'key' => 'signatories', 'path' => 'signatories/workflow', 'page_route' => 'signatories.workflow', 'update_route' => 'signatories.workflow.update',
    'title' => 'Signatories Alerts', 'subtitle' => 'Decide who is told when a signature is registered, verified, suspended or revoked, when a report could not show a required signature, and when the signing setup changes.',
    'record' => 'signature', 'owner_label' => 'the person the signature belongs to',
    'events' => [
        'registered' => W::ev('Signature waiting for verification', 'A signature picture has been registered and has to be verified before it can be used.', ['permission' => 'verify-signatures', 'step_owner' => true, 'sms_text' => $sms, 'icon' => 'edit', 'subject' => 'A signature is waiting for verification', 'body' => '#actor registered a signature for #signatory. It has to be verified before it can be used on reports.']),
        'registered_for_you' => W::ev('A signature was registered for you', 'Somebody with permission registered a signature for a user without waiting for that user\'s own confirmation. The user is told in the app bell so they know it exists.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'edit', 'subject' => 'A signature was registered for you', 'body' => '#actor registered a signature for you. It is not used on any report until it has been verified. If it is not yours, tell #actor or a Super Admin.']),
        'activated' => W::ev('Signature verified and active', 'A signature was verified and is now used on reports. The signatory can be told.', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'tick', 'subject' => 'Your signature is active', 'body' => 'Your signature was verified by #actor and is now used on the reports you sign.']),
        'rejected' => W::ev('Signature rejected', 'A registered signature was rejected during verification.', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'cross', 'subject' => 'Your signature was not accepted', 'body' => '#actor did not accept the signature you registered. Reason: #remarks']),
        'suspended' => W::ev('Signature suspended', 'A signature was suspended and will not be used on new reports.', ['roles' => ['Super Admin'], 'owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'pause', 'subject' => 'A signature was suspended', 'body' => '#actor suspended the signature of #signatory. Reason: #remarks']),
        'revoked' => W::ev('Signature revoked', 'A signature was revoked for good.', ['roles' => ['Super Admin'], 'owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'trash', 'subject' => 'A signature was revoked', 'body' => '#actor revoked the signature of #signatory. Reason: #remarks']),
        'missing_signature' => W::ev('A report needed a signature that was not available', 'A report or a final issue found that a required signature is missing (no picture on file, not active, or the workflow did not record who acted).', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'exclamation', 'subject' => 'A required signature is missing', 'body' => 'The #signingrole signature is missing on #document #reference: #reason']),
        'setup_changed' => W::ev('Signing setup changed', 'Signing roles, the document signing setup or the Signatory settings were changed.', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'cog', 'subject' => 'The signing setup changed', 'body' => '#actor changed the signing setup.']),
        'settings_changed' => W::ev('Signatory settings changed', 'The Signatory settings (ink colour, limits, retention) were changed.', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'cog', 'subject' => 'Signatory settings changed', 'body' => 'The Signatory settings were changed by #actor.']),
    ],
    'tags' => [
        W::tag('signatory', 'Signatories', 'Signatory', 'The person the signature belongs to.', 'Abdulhakeem Nassir'),
        W::tag('signingrole', 'Signatories', 'Signing role', 'The role the signature is for.', 'Approved By'),
        W::tag('document', 'Signatories', 'Document', 'The kind of document.', 'Payment Voucher'),
        W::tag('reference', 'Signatories', 'Reference', 'The reference number of the document.', 'PV-2026-0042'),
        W::tag('reason', 'Signatories', 'Reason', 'Why the signature could not be shown.', 'the approval was not recorded'),
    ],
];
