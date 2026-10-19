<?php

use App\Support\Workflow\WorkflowModules as W;

// Smart Brief tells nobody anything by itself: every event starts OFF. Switch on what the organisation wants to be transparent about. "The
// person the notice is for" is the person whose brief was opened, so a person can be told when somebody looks at their activity.
$sms = "WIMS - #title\n#message";
$who = 'the person whose Smart Brief was opened';

return [
    'key' => 'smart_brief', 'path' => 'smart-brief/workflow', 'page_route' => 'smart-brief.workflow', 'update_route' => 'smart-brief.workflow.update',
    'title' => 'Smart Brief Alerts', 'subtitle' => 'Decide who is told when somebody opens another person\'s Smart Brief, a team summary, or exports a report about other people, and when the Smart Brief settings change. Everything starts off.',
    'record' => 'brief', 'owner_label' => $who,
    'events' => [
        'brief_viewed' => W::ev('Somebody opened a person\'s brief', 'A manager opens another person\'s Smart Brief. The person whose brief it is can be told, and so can the roles and people chosen here. One notice per viewer and person every half hour.', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'eye', 'subject' => 'Your Smart Brief was opened', 'body' => '#viewer opened your Smart Brief (#period).']),
        'team_viewed' => W::ev('Team summary opened', 'A manager opens the team summary (counts of recorded activity for the people they may see).', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'group', 'subject' => 'Team summary opened', 'body' => '#viewer opened the Smart Brief team summary (#period).']),
        'report_exported' => W::ev('Report with other people\'s activity exported', 'A Smart Brief report that contains other people\'s activity is printed, saved as PDF or downloaded as CSV.', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'printer', 'subject' => 'Smart Brief report exported', 'body' => '#viewer exported the Smart Brief report "#report" (#period) as #format.']),
        'settings_changed' => W::ev('Smart Brief settings changed', 'A Super Admin saves the Smart Brief settings (default period, limits, wording options).', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'cog', 'subject' => 'Smart Brief settings changed', 'body' => 'The Smart Brief settings were changed by #viewer.']),
    ],
    'tags' => [
        W::tag('viewer', 'Smart Brief', 'Viewer', 'The person who opened the brief or exported the report.', 'Abdulhakeem Nassir'),
        W::tag('period', 'Smart Brief', 'Period', 'The period that was opened.', 'Today'),
        W::tag('report', 'Smart Brief', 'Report', 'The name of the report that was exported.', 'Team Activity Summary'),
        W::tag('format', 'Smart Brief', 'Format', 'Print, PDF or CSV.', 'PDF'),
    ],
];
