<?php

use App\Support\Workflow\WorkflowModules as W;

// Starting values reproduce the Site Meetings notification rules: bell only, to the people of the meeting. Which people of the meeting
// each notice points at (invited, chairperson, minutes writer, who prepared it, the site team) is set under "People of the meeting" on
// the Site Meetings settings page; "the person the notice is for" below means exactly those people.
$sms = "WIMS - #title: #message";

return [
    'key' => 'site_meetings', 'path' => 'site-meetings/workflow', 'page_route' => 'site-meetings.workflow', 'update_route' => 'site-meetings.workflow.update',
    'title' => 'Site Meetings Alerts', 'subtitle' => 'Decide who is told about each Site Meetings event, on which channels and with which words. The person who does the action is never notified about it.',
    'record' => 'site meeting',
    // Who sees which meeting is already decided by SiteMeeting::visibleTo(): the user's sites, meetings they are invited to, and "View all Site Meetings". Those are in Who Does What / the user's projects, so no generic model is added.
    'owner_label' => 'the people of the meeting (chosen per notice under "People of the meeting" on the Site Meetings settings page)',
    'events' => [
        'scheduled' => W::ev('Meeting scheduled', 'A meeting is scheduled and people are invited.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'calendar']),
        'changed' => W::ev('Meeting changed or moved', 'The date, time, site or venue changes, also when it is dragged to another day.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'refresh']),
        'status' => W::ev('Held / postponed / cancelled', 'The status of a meeting changes.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'tick']),
        'deleted' => W::ev('Meeting deleted', 'A meeting is deleted.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'trash']),
        'minutes_submitted' => W::ev('Minutes submitted', 'The site engineer submits the minutes.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'doc-text']),
        'minutes_finalised' => W::ev('Minutes finalised', 'The minutes are finalised and locked.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'lock-closed']),
        'minutes_reopened' => W::ev('Minutes reopened', 'Finalised minutes are reopened for a correction.', ['owner' => true, 'sms_text' => $sms, 'icon' => 'lock-open']),
        'restored' => W::ev('Meeting restored', 'A deleted meeting is brought back.', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'refresh']),
        'minutes_saved' => W::ev('Minutes saved', 'The minutes are saved with changes (draft or copied from an earlier meeting).', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'edit']),
        'attachment_added' => W::ev('Attachment added', 'A file is attached to an existing meeting.', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'clip']),
        'attachment_removed' => W::ev('Attachment removed', 'A file is removed from a meeting.', ['owner' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'trash']),
        'settings_changed' => W::ev('Site Meetings settings changed', 'The week layout, reminders, people of the meeting, status wording or the minutes layout is changed (or reset to the WIMS default) on the Site Meetings settings page.', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'cog']),
        'reminder' => W::ev('Reminder', 'Sent on the day of the meeting and the chosen number of days before (set on the Site Meetings settings page).', ['owner' => true, 'sms_text' => $sms, 'icon' => 'clock']),
    ],
    'tags' => [
        W::tag('reference', 'Meeting', 'Meeting number', 'The meeting reference.', 'SM-0042'),
        W::tag('meeting', 'Meeting', 'Meeting title', 'The title of the meeting.', 'Management Meeting 12'),
        W::tag('project', 'Meeting', 'Project / Warehouse', 'The site the meeting is held at.', 'MOMBASA MARKET'),
        W::tag('when', 'Meeting', 'Date and time', 'When the meeting is held.', 'Mon, 12 Oct 2026 at 09:00'),
        W::tag('changes', 'Change', 'What was changed', 'A short description of what was changed (minutes sections, file name, settings).', 'Visitors, Testing / samples'),
        W::tag('venue', 'Meeting', 'Venue', 'Where the meeting is held (empty if not set).', 'Site office'),
    ],
];
