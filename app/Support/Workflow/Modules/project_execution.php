<?php

use App\Support\Workflow\WorkflowModules as W;

$pm = ['Super Admin', 'Admin', 'Project Manager', 'Project Managers'];

return [
    'key' => 'project_execution', 'path' => 'project-execution/workflow', 'page_route' => 'project-execution.workflow', 'update_route' => 'project-execution.workflow.update',
    'title' => 'Project Execution Alerts', 'subtitle' => 'Decide who is told about work package issues and daily updates, on which channels and with which words.',
    'record' => 'work package', 'owner_label' => 'the person the notice is about: the issue\'s assignee or reporter, or the person who sent the daily update',
 // the limit is on Issues (the records with a reporter and a status); work packages themselves are not limited
    'events' => [
        'issue_assigned' => W::ev('Issue Assigned', 'A work package issue is given to a person (when it is reported with an assignee, or the assignee is changed): that person is told.', ['owner' => true, 'icon' => 'user']),
        'issue_reported' => W::ev('Issue Reported', 'A new issue is reported on a work package.', ['enabled' => false, 'roles' => $pm, 'project' => true, 'icon' => 'exclamation']),
        'issue_resolved' => W::ev('Issue Resolved', 'An issue is marked Resolved: the person who reported it is told.', ['enabled' => false, 'owner' => true, 'icon' => 'tick']),
        'daily_update_submitted' => W::ev('Daily Update Submitted', 'A daily update is sent for approval.', ['enabled' => false, 'roles' => $pm, 'project' => true, 'icon' => 'clock']),
        'daily_update_approved' => W::ev('Daily Update Approved', 'A daily update is approved and the progress of the work package is updated: the person who sent it is told.', ['enabled' => false, 'owner' => true, 'icon' => 'tick']),
        'daily_update_rejected' => W::ev('Daily Update Rejected', 'A daily update is rejected with remarks: the person who sent it is told.', ['enabled' => false, 'owner' => true, 'icon' => 'cross']),
    ],
    'tags' => [
        W::tag('workpackage', 'Work package', 'Work package', 'The reference and title of the work package.', 'WP-0012 Foundation'),
        W::tag('project', 'Work package', 'Project / Warehouse', 'The project the work package belongs to.', 'MOMBASA MARKET'),
        W::tag('reference', 'Issue / update', 'Issue number', 'The issue reference (issue events only).', 'RWI-0007'),
        W::tag('issue', 'Issue / update', 'Issue title', 'The title of the issue (issue events only).', 'Cracks in slab'),
        W::tag('priority', 'Issue / update', 'Priority', 'The priority of the issue (issue events only).', 'High'),
        W::tag('assignee', 'Issue / update', 'Assigned to', 'The person the issue is assigned to (issue events only).', 'Hamisi Juma'),
        W::tag('progress', 'Issue / update', 'Progress', 'The progress reported in the daily update (update events only).', '65%'),
    ],
];
