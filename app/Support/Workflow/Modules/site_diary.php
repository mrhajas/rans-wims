<?php

use App\Support\Workflow\WorkflowModules as W;

// Starting values reproduce the notification part of the Site Diary settings page: Super Admins, bell on, email off.
return [
    'key' => 'site_diary', 'path' => 'site-diary/workflow', 'page_route' => 'site-diary.workflow', 'update_route' => 'site-diary.workflow.update',
    'title' => 'Site Diary Alerts', 'subtitle' => 'Decide who is told about Site Diary events, on which channels and with which words. The time of the daily "diary missing" message is set on the Site Diary settings page.',
    'record' => 'site diary', 'owner_label' => 'nobody in particular',
    // Who sees which diary is already decided by SiteDiary::visibleTo(): the user's assigned projects, and "View all Site Diaries" (otherwise only their own). Both are in Who Does What / the user's projects, so no generic model is added.
    'events' => [
        'new_diary' => W::ev('New Site Diary created', 'A diary was recorded for any project.', ['roles' => ['Super Admin'], 'icon' => 'doc-text']),
        'new_assigned_project' => W::ev('New Site Diary for an assigned project', 'Only the people assigned to that project, within the roles chosen here.', ['roles' => ['Project Manager', 'Project Managers'], 'project' => true, 'icon' => 'users']),
        'new_photos' => W::ev('New photos added', 'Photos were added to a diary.', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'camera']),
        'new_videos' => W::ev('New videos added', 'Videos were added to a diary.', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'video']),
        'diary_updated' => W::ev('Site Diary edited', 'The remarks, date or project of a diary is changed.', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'edit']),
        'diary_deleted' => W::ev('Site Diary deleted', 'A diary is deleted, with the reason given (it can be restored).', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'trash']),
        'diary_restored' => W::ev('Site Diary restored', 'A deleted diary is brought back.', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'refresh']),
        'media_removed' => W::ev('Photo or video removed', 'A photo or video is removed from a diary.', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'cross']),
        'drive_upload_failed' => W::ev('Google Drive upload failed', 'A photo or video could not be sent to Google Drive (it stays safe on the server and is retried; this tells you when it keeps failing).', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'exclamation']),
        'settings_changed' => W::ev('Site Diary settings changed', 'The Site Diary settings, the Google Drive connection or the Drive storage are changed or cleared on the Site Diary settings page.', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'cog']),
        'missing_diary' => W::ev('Site Diary missing for the day', 'Projects that require a daily diary and have none for today, sent once a day at the time set on the Site Diary settings page.', ['roles' => ['Super Admin'], 'enabled' => false, 'icon' => 'clock']),
    ],
    'tags' => [
        W::tag('reference', 'Site diary', 'Diary number', 'The Site Diary number.', 'SD-0042'),
        W::tag('project', 'Site diary', 'Project / Warehouse', 'The project the diary is for.', 'MOMBASA MARKET'),
        W::tag('date', 'Site diary', 'Diary date', 'The date of the diary (or the day without a diary).', '12 Oct 2026'),
        W::tag('count', 'Site diary', 'Number of files', 'How many photos or videos were added.', '3'),
        W::tag('changes', 'Change', 'What was changed', 'What was changed, removed or cleared (a short description).', 'remarks, date'),
        W::tag('reason', 'Change', 'Reason', 'The reason given when a diary is deleted.', 'Entered by mistake'),
        W::tag('filename', 'Site diary', 'File name', 'The photo or video concerned.', 'IMG_2041.jpg'),
        W::tag('projects', 'Missing diary', 'Projects without a diary', 'The names of the projects with no diary (first six).', 'MOMBASA MARKET, ZANZIBAR HOTEL'),
        W::tag('projectscount', 'Missing diary', 'Number of projects', 'How many projects have no diary.', '2'),
    ],
];
