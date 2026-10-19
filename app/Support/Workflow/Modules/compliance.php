<?php

use App\Support\Workflow\WorkflowModules as W;

$off = ['enabled' => false, 'bell' => true];

return [
    'key' => 'compliance', 'path' => 'compliance-records/workflow', 'page_route' => 'compliance-records.workflow', 'update_route' => 'compliance-records.workflow.update',
    'title' => 'Compliance Alerts', 'subtitle' => 'Decide who is told about compliance renewals, escalations and changes to a compliance record, on which channels and with which words.',
    'record' => 'compliance record', 'owner_label' => 'the responsible person of the record and the extra people chosen on it',
    'step_note' => 'The renewal reminder goes to the responsible person and the extra recipients chosen on each record, on the days set on that record. The escalation goes to the record\'s escalation recipient 14, 7 and 0 days before expiry and 1 day after. A person who switched off compliance emails in their own profile still gets the bell notice but no email.',
    'events' => [
        'reminder' => W::ev('Renewal Reminder', 'A record reaches one of its reminder days before expiry (checked once a day at 06:00).', ['owner' => true, 'email' => true, 'icon' => 'clock']),
        'escalation' => W::ev('Expiry Escalation', 'A record is 14, 7 or 0 days from expiry, or has just expired: the escalation recipient chosen on the record is told.', ['owner' => true, 'email' => true, 'icon' => 'exclamation']),
        'created' => W::ev('Compliance Record Created', 'A new compliance record is added.', $off + ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'icon' => 'plus']),
        'cancelled' => W::ev('Compliance Record Cancelled', 'A compliance record is cancelled.', $off + ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'icon' => 'cross']),
        'renewal_submitted' => W::ev('Renewal Submitted', 'A renewal application is submitted and waits for approval.', $off + ['permission' => 'approve-compliance-renewal', 'owner' => true, 'icon' => 'clock']),
        'renewal_approved' => W::ev('Renewal Approved', 'A renewal is approved and waits for final sign-off.', $off + ['permission' => 'complete-compliance-renewal', 'owner' => true, 'icon' => 'tick']),
        'renewal_completed' => W::ev('Renewal Completed', 'A renewal is completed and the record has a new expiry date.', $off + ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'icon' => 'tick']),
        'renewal_rejected' => W::ev('Renewal Rejected', 'A renewal is rejected.', $off + ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'icon' => 'cross']),
        'updated' => W::ev('Compliance Record Edited', 'A compliance record is edited and saved.', $off + ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'icon' => 'edit']),
        'renewal_started' => W::ev('Renewal Started', 'A renewal is started for a compliance record.', $off + ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'icon' => 'refresh']),
        'renewal_cancelled' => W::ev('Renewal Cancelled', 'A renewal in progress is cancelled.', $off + ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'icon' => 'cross']),
        'document_added' => W::ev('Compliance Document Uploaded', 'A document is uploaded to a compliance record.', $off + ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'icon' => 'clip']),
        'document_removed' => W::ev('Compliance Document Removed', 'A document of a compliance record is removed.', $off + ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'icon' => 'clip']),
        'category_created' => W::ev('Compliance Category Created', 'A new compliance category is added.', $off + ['roles' => ['Super Admin', 'Admin'], 'icon' => 'plus']),
        'category_updated' => W::ev('Compliance Category Edited', 'A compliance category is renamed, regrouped or switched on or off.', $off + ['roles' => ['Super Admin', 'Admin'], 'icon' => 'edit']),
        'category_deleted' => W::ev('Compliance Category Deleted', 'A compliance category is deleted.', $off + ['roles' => ['Super Admin', 'Admin'], 'icon' => 'trash']),
    ],
    'tags' => [
        W::tag('record', 'Compliance', 'Record title', 'The title of the compliance record.', 'Business Licence 2026'),
        W::tag('number', 'Compliance', 'Record number', 'The compliance number.', 'CMP-0042'),
        W::tag('type', 'Compliance', 'Type', 'The compliance type.', 'Business Licence'),
        W::tag('days', 'Compliance', 'Days left', 'Days to expiry (negative once it has expired).', '14'),
        W::tag('expirydate', 'Compliance', 'Expiry date', 'The expiry date of the record.', '26 Oct 2026'),
        W::tag('responsible', 'Compliance', 'Responsible person', 'The person responsible for the record.', 'Hamisi Juma'),
        W::tag('status', 'Compliance', 'Status', 'The status of the record.', 'Due Soon'),
        W::tag('file', 'Compliance', 'Document', 'The type or name of the document added or removed.', 'Renewal Receipt'),
        W::tag('category', 'Compliance', 'Category name', 'The compliance category the notice is about (category events only).', 'Licences and Permits'),
    ],
];
