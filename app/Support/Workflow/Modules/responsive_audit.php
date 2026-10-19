<?php

use App\Support\Workflow\WorkflowModules as W;

// Responsive Audit tells nobody anything until a Super Admin switches an event on. The starting audience is the Super Admin role.
$sms = "WIMS - #title\n#message";

return [
    'key' => 'responsive_audit', 'path' => 'responsive-audit/workflow', 'page_route' => 'responsive-audit.workflow', 'update_route' => 'responsive-audit.workflow.update',
    'title' => 'Responsive Audit Alerts', 'subtitle' => 'Decide who is told when an audit finishes with findings, when a new audit finds more problems than the one before it, and when the audit standards change. Everything starts off.',
    'record' => 'audit', 'owner_label' => 'the person who ran the audit',
    'events' => [
        'audit_completed' => W::ev('Audit finished with findings', 'An audit run finishes and found at least one wrapped button, clipped control, small touch target, sideways scroll or dialog problem.', ['roles' => ['Super Admin'], 'owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'adjustments', 'subject' => 'Responsive audit finished', 'body' => '#actor ran a responsive audit at #width px: #findings finding(s) on #pages page(s).']),
        'audit_regression' => W::ev('More problems than the last audit', 'An audit run finds more problems than the previous run at the same width.', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'exclamation', 'subject' => 'Responsive audit: more problems than before', 'body' => 'The audit at #width px found #findings finding(s), up from #previous in the last run.']),
        'settings_changed' => W::ev('Audit standards changed', 'The audit standards (smallest touch target, widths, pages left out) are changed.', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'cog', 'subject' => 'Responsive audit standards changed', 'body' => 'The responsive audit standards were changed by #actor.']),
    ],
    'tags' => [
        W::tag('width', 'Audit', 'Width', 'The screen width that was audited, in pixels.', '390'),
        W::tag('findings', 'Audit', 'Findings', 'How many problems the audit found.', '12'),
        W::tag('pages', 'Audit', 'Pages', 'How many pages the audit opened.', '306'),
        W::tag('previous', 'Audit', 'Previous findings', 'The number of problems in the previous run at the same width.', '8'),
    ],
];
