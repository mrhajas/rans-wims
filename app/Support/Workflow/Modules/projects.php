<?php

use App\Support\Workflow\WorkflowModules as W;

$hr = ['roles' => ['Super Admin', 'Admin'], 'enabled' => false];

return [
    'key' => 'projects', 'path' => 'warehouses/workflow', 'page_route' => 'warehouses.workflow', 'update_route' => 'warehouses.workflow.update',
    'title' => 'Projects Alerts', 'subtitle' => 'Decide who is told when a project is created, changed, completed or deleted, when its BOQ is overused or issued material is overdue, on which channels and with which words.',
    'record' => 'project', 'owner_label' => 'nobody in particular',
    'events' => [
        'created' => W::ev('Project Created', 'A new project / warehouse is created.', $hr + ['icon' => 'plus', 'subject' => 'Project created: #project', 'body' => '#project was created by #actor.', 'sms_text' => "WIMS - Project Created\n#project\nBy: #actor"]),
        'updated' => W::ev('Project Updated', 'A project / warehouse is edited.', $hr + ['icon' => 'edit', 'subject' => 'Project updated: #project', 'body' => '#project was updated by #actor.', 'sms_text' => "WIMS - Project Updated\n#project\nBy: #actor"]),
        'activated' => W::ev('Project Activated', 'A project / warehouse is made active.', $hr + ['icon' => 'tick', 'subject' => 'Project activated: #project', 'body' => '#project was activated by #actor.', 'sms_text' => "WIMS - Project Activated\n#project\nBy: #actor"]),
        'deactivated' => W::ev('Project Deactivated', 'A project / warehouse is made inactive.', $hr + ['icon' => 'cross', 'subject' => 'Project deactivated: #project', 'body' => '#project was deactivated by #actor.', 'sms_text' => "WIMS - Project Deactivated\n#project\nBy: #actor"]),
        'completed' => W::ev('Project Completed', 'A project is marked as completed (it is then locked).', $hr + ['icon' => 'tick', 'subject' => 'Project completed: #project', 'body' => '#project was marked as completed by #actor.', 'sms_text' => "WIMS - Project Completed\n#project\nBy: #actor"]),
        'reopened' => W::ev('Project Re-opened', 'A completed project is re-opened.', $hr + ['icon' => 'refresh', 'subject' => 'Project re-opened: #project', 'body' => '#project was re-opened by #actor.', 'sms_text' => "WIMS - Project Re-opened\n#project\nBy: #actor"]),
        'deleted' => W::ev('Project Deleted', 'A project / warehouse is deleted.', $hr + ['icon' => 'trash', 'subject' => 'Project deleted: #project', 'body' => '#project was deleted by #actor.', 'sms_text' => "WIMS - Project Deleted\n#project\nBy: #actor"]),
        'restored' => W::ev('Project Restored', 'A deleted project / warehouse is restored.', $hr + ['icon' => 'refresh', 'subject' => 'Project restored: #project', 'body' => '#project was restored by #actor.', 'sms_text' => "WIMS - Project Restored\n#project\nBy: #actor"]),
        'permanently_deleted' => W::ev('Project Deleted for Good', 'A project / warehouse is removed from the deleted records for good. When this is switched on it is used instead of "Project Deleted" for this step.', $hr + ['icon' => 'trash', 'subject' => 'Project deleted for good: #project', 'body' => '#project was deleted for good by #actor.', 'sms_text' => "WIMS - Project Deleted For Good\n#project\nBy: #actor"]),
        'imported' => W::ev('Projects Imported', 'Projects / warehouses are imported from an Excel file (new ones are added, existing names are updated).', $hr + ['icon' => 'in', 'subject' => 'Projects imported: #count', 'body' => '#actor imported #count project(s) from a file.', 'sms_text' => "WIMS - Projects Imported\n#count project(s)\nBy: #actor"]),
        'boq_slight_overuse' => W::ev('BOQ Slight Overuse (over 80%)', 'The issued quantity of a BOQ line crosses 80% of its baseline.', ['roles' => ['Project Manager', 'Project Managers'], 'project' => true, 'bell' => false, 'icon' => 'chart-bar', 'aliases' => ['wc:boq-slight-overuse']]),
        'boq_overused' => W::ev('BOQ Overused (over 110%)', 'The issued quantity of a BOQ line exceeds 110% of its baseline.', ['roles' => ['Project Manager', 'Project Managers', 'Super Admin', 'Admin'], 'project' => true, 'bell' => false, 'icon' => 'exclamation', 'aliases' => ['wc:boq-overused']]),
        'issue_overdue' => W::ev('Issued Material Overdue', 'Issued material passes its expected return date without being returned: the person who issued it and the Site Engineers of that project are told.', ['roles' => \App\Models\User::SITE_ENGINEER_ROLES, 'owner' => true, 'project' => true, 'bell' => false, 'icon' => 'clock', 'aliases' => ['wc:issue-overdue']]),
    ],
    'tags' => [
        W::tag('project', 'Project', 'Project / Warehouse', 'The project the notice is about.', 'MOMBASA MARKET'),
        W::tag('count', 'Import', 'Number of projects', 'How many projects an import handled.', '6'),
    ],
];
