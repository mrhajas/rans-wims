<?php

use App\Support\Workflow\WorkflowModules as W;

$off = ['enabled' => false, 'roles' => ['Super Admin', 'Admin'], 'bell' => true];

return [
    'key' => 'executive_reports', 'path' => 'executive-reports/workflow', 'page_route' => 'executive-reports.workflow', 'update_route' => 'executive-reports.workflow.update',
    'title' => 'Executive Report Alerts', 'subtitle' => 'Decide who is told when the weekly Executive Intelligence Report has gone out or failed, and when its schedule, content or subscribers are changed. (Who receives the report itself is set on the Executive Report page.)',
    'record' => 'report', 'owner_label' => 'nobody in particular',
    'events' => [
        'weekly_sent' => W::ev('Weekly Report Sent', 'This week\'s Executive Intelligence Report was sent to its subscribers.', ['bell' => false, 'icon' => 'chart-bar', 'aliases' => ['em:executive-intelligence-report-weekly']]),
        'send_failed' => W::ev('Weekly Report Failed', 'The weekly Executive Intelligence Report could not be built or sent (the reason is in the message).', $off + ['icon' => 'exclamation', 'subject' => 'Executive report failed', 'body' => 'The weekly Executive Intelligence Report for #week could not be sent. #reason']),
        'settings_changed' => W::ev('Report Schedule Changed', 'The day, time or on / off switch of the weekly report is changed.', $off + ['icon' => 'cog']),
        'content_changed' => W::ev('Report Content Changed', 'The sections, projects, items or limits the weekly report covers are changed.', $off + ['icon' => 'edit']),
        'subscriber_added' => W::ev('Report Subscriber Added', 'People are added to the list that receives the weekly report.', $off + ['icon' => 'plus']),
        'subscriber_changed' => W::ev('Report Subscriber Switched', 'A subscriber is switched on or off.', $off + ['icon' => 'refresh']),
        'subscriber_removed' => W::ev('Report Subscriber Removed', 'A subscriber is removed from the weekly report.', $off + ['icon' => 'trash']),
    ],
    'tags' => [
        W::tag('week', 'Report', 'Week', 'The week the report covers.', '05 Oct to 11 Oct 2026'),
        W::tag('reason', 'Report', 'Reason', 'Why the report failed (failure only).', 'SMTP connection refused'),
        W::tag('subscriber', 'Report', 'Subscriber', 'The name of the subscriber added, switched or removed.', 'Neema Salum'),
    ],
];
