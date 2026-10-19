<?php

use App\Support\Workflow\WorkflowModules as W;

$new = ['roles' => ['Super Admin', 'Admin'], 'enabled' => false, 'email' => false];

return [
    'key' => 'task_manager', 'path' => 'task-manager/workflow', 'page_route' => 'task-manager.workflow', 'update_route' => 'task-manager.workflow.update',
    'title' => 'Task Manager Alerts', 'subtitle' => 'Decide who is told when a task is assigned, moves, is commented on or falls due, on which channels and with which words.',
    'record' => 'task',
    // Task Manager already limits by permission (View Own / Assigned Tasks vs View All Tasks, private tasks); these rules narrow further, per role.
    'owner_label' => 'the task\'s creator and assignees (for Task Assigned: the people just assigned)',
    'step_note' => 'Task reminders are sent when the due date and time arrive (or the number of hours earlier chosen on Task Manager > Settings). The person who just did something is never told about their own action.',
    'events' => [
        'assigned' => W::ev('Task Assigned', 'Someone is assigned to a task: the people just assigned are told (plus anyone added here).', [
            'owner' => true, 'email' => true, 'sms_text' => 'WIMS: "#task" was assigned to #assignees by #actor.#duetext', 'subject' => '#title', 'body' => '#message', 'icon' => 'users',
        ]),
        'status_changed' => W::ev('Task Status Changed', 'A task moves to a different status / column: its creator and current assignees are told (not the person who moved it).', [
            'owner' => true, 'email' => true, 'sms_text' => 'WIMS: "#task" moved to #status by #actor.', 'icon' => 'refresh',
        ]),
        'commented' => W::ev('Task Comment Added', 'Someone comments on a task: its creator and current assignees are told (not the person who commented).', [
            'owner' => true, 'email' => true, 'sms_text' => 'WIMS: #actor commented on "#task": #commentshort', 'icon' => 'sms',
        ]),
        'due_reminder' => W::ev('Task Due Reminder', 'A task\'s due date and time arrive (or the hours earlier set on Task Manager > Settings): its creator and current assignees are reminded once.', [
            'owner' => true, 'email' => true, 'sms_text' => 'WIMS: "#task" #when (#duelabel).', 'icon' => 'clock',
        ]),
        'deleted' => W::ev('Task Deleted', 'A task is deleted: its creator and current assignees are told (not the person who deleted it). New: off until switched on.', [
            'owner' => true, 'email' => true, 'enabled' => false, 'sms_text' => 'WIMS: "#task" was deleted by #actor.', 'icon' => 'trash',
        ]),
        'superadmin_alert' => W::ev('Super Admin Alert (app and email)', 'Every Super Admin who created a task or is assigned to it hears about everything that happens to it (assigned, moved, comment, deleted, due), in the app and by email, even when the event above does not reach them. A Super Admin already emailed by the event above is not told twice. Switch this off to stop these extra alerts.', [
            'owner' => true, 'email' => true, 'icon' => 'lock-closed',
        ]),
        'superadmin_sms' => W::ev('Super Admin Alert (SMS)', 'The same Super Admins also get an SMS when a task is assigned, moves or falls due (not for comments or deletions), unless the event above already texted them. Switch this off to stop these extra SMS.', [
            'owner' => true, 'bell' => false, 'sms' => true, 'sms_text' => '#smsline', 'icon' => 'lock-closed',
        ]),
        'personal_reminder' => W::ev('Personal Reminder Due', 'A personal reminder from Task Reminders reaches its time: the person who set it is told in the app.', [
            'owner' => true, 'subject' => '#title', 'body' => '#message', 'sms_text' => 'WIMS: #title. #message', 'icon' => 'clock',
        ]),
        'personal_reminder_email' => W::ev('Personal Reminder Due (email)', 'The email copy of a personal reminder. It goes only to people who switched on "email me my reminders" in their own profile; everyone else keeps the in-app notice only.', [
            'owner' => true, 'bell' => false, 'email' => true, 'subject' => '#title, reminder due', 'body' => '#message', 'icon' => 'email',
        ]),
        // new events: switched off until a Super Admin turns them on
        'created' => W::ev('Task Created', 'A new task is created: its creator and assignees are told (not the person who created it). New: off until switched on.', $new + ['owner' => true, 'subject' => 'Task created: #task', 'body' => '#message', 'sms_text' => 'WIMS: "#task" was created by #actor.', 'icon' => 'plus']),
        'updated' => W::ev('Task Edited', 'A task\'s details (title, due date, priority ...) are edited: its creator and assignees are told (not the person who edited it). New: off until switched on.', $new + ['owner' => true, 'subject' => 'Task edited: #task', 'body' => '#message', 'sms_text' => 'WIMS: "#task" was edited by #actor.', 'icon' => 'edit']),
        'unassigned' => W::ev('Task Unassigned', 'Someone is taken off a task: the people just removed are told (plus anyone added here). New: off until switched on.', $new + ['owner' => true, 'subject' => '#title', 'body' => '#message', 'sms_text' => 'WIMS: you were taken off "#task" by #actor.', 'icon' => 'cross']),
        'checklist_changed' => W::ev('Task Checklist Changed', 'A checklist item of a task is added, ticked or un-ticked, edited or removed: its creator and assignees are told (not the person who did it). New: off until switched on.', $new + ['owner' => true, 'subject' => 'Checklist changed: #task', 'body' => '#message', 'sms_text' => 'WIMS: checklist of "#task" changed by #actor.', 'icon' => 'list']),
        'settings_changed' => W::ev('Task Manager Settings Changed', 'The task reminder timing is changed on Task Manager > Settings. New: off until switched on.', $new + ['subject' => 'Task Manager settings changed', 'body' => '#message', 'sms_text' => "WIMS: Task Manager settings changed by #actor.", 'icon' => 'cog']),
    ],
    'tags' => [
        W::tag('task', 'Task', 'Task title', 'The title of the task.', 'Prepare the July stock report'),
        W::tag('status', 'Task', 'Status', 'Where the task is now (for a move: the new column).', 'in progress'),
        W::tag('assignees', 'Task', 'Assigned to', 'The people assigned to the task.', 'Flow PM, Salha Shaaban'),
        W::tag('duelabel', 'Task', 'Due date and time', 'When the task is due.', 'Oct 12, 2026 at 2:00 PM'),
        W::tag('duetext', 'Task', 'Due sentence', 'The sentence "Due Oct 12." (empty when the task has no due date).', ' Due Oct 12.'),
        W::tag('when', 'Task', 'Due wording', 'For a reminder: "is due now" or "is due in 8 hours".', 'is due now'),
        W::tag('commentshort', 'Task', 'Comment (short)', 'The first 80 characters of the comment.', 'Please attach the signed copy'),
        W::tag('comment', 'Task', 'Comment', 'The first 120 characters of the comment.', 'Please attach the signed copy before Friday'),
        W::tag('smsline', 'Super Admin alert', 'SMS line', 'The one-line SMS written for the kind of alert (assigned, moved, due).', 'WIMS: "Prepare the July stock report" moved to done by Flow PM.'),
    ],
];
