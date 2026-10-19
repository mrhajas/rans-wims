<?php

use App\Support\Workflow\WorkflowModules as W;

return [
    'key' => 'site_reports', 'path' => 'daily-site-reports/workflow', 'page_route' => 'daily-site-reports.workflow', 'update_route' => 'daily-site-reports.workflow.update',
    'title' => 'Site Reports Alerts', 'subtitle' => 'Decide who is told when a daily site report is submitted, on which channels and with which words.',
    'record' => 'site report', 'owner_label' => 'the site engineer who submitted it',
    'events' => [
        'report' => W::ev('Site Report Email Report', 'The full email report (details and the names of the attachments, never the files) when a daily site report is submitted.', ['bell' => false, 'email' => true, 'icon' => 'email', 'subject' => '#title', 'body' => '#message', 'roles' => ['Super Admin']]),
        'submitted' => W::ev('Site Report Submitted', 'A daily site report is submitted: Admins and the Project Managers of that project are told.', ['roles' => ['Super Admin', 'Admin', 'Project Manager', 'Project Managers'], 'project' => true, 'icon' => 'clip', 'aliases' => ['em:site-report-submitted']]),
        'admin_copy' => W::ev('Site Report Submitted: Copy', 'A direct copy of the submission notice, sent independently of the notice above.', ['bell' => false, 'icon' => 'doc', 'aliases' => ['em:site-report-submitted-admin-copy']]),
        'updated' => W::ev('Site Report Edited', 'A submitted site report is edited and saved.', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'project' => true, 'enabled' => false, 'icon' => 'edit', 'subject' => 'Site report edited: #reference', 'body' => '#reference for #project was edited by #actor.', 'sms_text' => "WIMS - Site Report Edited\nReport: #reference\nProject: #project\nBy: #actor"]),
        'deleted' => W::ev('Site Report Deleted', 'A site report is deleted.', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'project' => true, 'enabled' => false, 'icon' => 'trash', 'subject' => 'Site report deleted: #reference', 'body' => '#reference for #project was deleted by #actor.', 'sms_text' => "WIMS - Site Report Deleted\nReport: #reference\nProject: #project\nBy: #actor"]),
        'archived' => W::ev('Site Report Archived', 'A site report is archived by a person, or automatically after the retention period (its attachment files are then removed).', ['roles' => ['Super Admin', 'Admin'], 'owner' => true, 'project' => true, 'enabled' => false, 'icon' => 'doc', 'subject' => 'Site report archived: #reference', 'body' => '#message', 'sms_text' => "WIMS - Site Report Archived\nReport: #reference\nProject: #project"]),
        'reminder' => W::ev('Missing Site Report Reminder', 'A Site Engineer has not submitted today\'s report (or missed yesterday\'s) for a project they are assigned to: they get a reminder at 17:00 and at 08:00 the next morning.', ['owner' => true, 'icon' => 'clock', 'subject' => 'Site Report Reminder', 'body' => '#message', 'sms_text' => "WIMS - Site Report Reminder\n#message"]),
    ],
    'tags' => [
        W::tag('date', 'Site report', 'Report date', 'The day the report is for (reminders).', '12 Oct 2026'),
        W::tag('reference', 'Site report', 'Report number', 'The site report number.', 'DSR-0042'),
        W::tag('project', 'Site report', 'Project / Warehouse', 'The project the report is for.', 'MOMBASA MARKET'),
        W::tag('creator', 'Site report', 'Submitted by', 'The site engineer.', 'Salha Shaaban'),
    ],
];
