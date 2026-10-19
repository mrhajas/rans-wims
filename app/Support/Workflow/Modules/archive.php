<?php

use App\Support\Workflow\WorkflowModules as W;

// Starting values reproduce the Archive Notifications page: Super Admins only, bell on, email off. Nobody is told about a record they cannot open.
$su = ['Super Admin'];

return [
    'key' => 'archive', 'path' => 'archive/workflow', 'page_route' => 'archive.workflow', 'update_route' => 'archive.workflow.update',
    'title' => 'Archive Alerts', 'subtitle' => 'Decide who is told about each Archive event, on which channels and with which words. The time of the daily expiry summary is set on the Archive Notifications page. People are never told about a confidential record they are not allowed to open.',
    'record' => 'archive record', 'owner_label' => 'nobody in particular',
    // Confidential / Restricted records are already hidden by ArchiveRecord::visibleTo() (the "See Confidential" permission); these rules narrow further, per role.
    'events' => [
        'restricted_record_created' => W::ev('Confidential record created', 'A Confidential or Restricted record is added to the Archive.', ['roles' => $su, 'icon' => 'lock-closed']),
        'record_created' => W::ev('Any record created', 'Every new Archive record, whatever its confidentiality. Can be noisy.', ['roles' => $su, 'enabled' => false, 'icon' => 'plus']),
        'confidentiality_changed' => W::ev('Confidentiality changed', 'A record is made more or less confidential.', ['roles' => $su, 'icon' => 'adjustments']),
        'restricted_downloaded' => W::ev('Confidential document downloaded', 'Someone downloads a file from a Confidential or Restricted record.', ['roles' => $su, 'icon' => 'download']),
        'restricted_opened' => W::ev('Confidential document opened', 'Someone previews or opens (new tab) a file from a Confidential or Restricted record.', ['roles' => $su, 'enabled' => false, 'icon' => 'eye']),
        'record_deleted' => W::ev('Record deleted', 'An Archive record is deleted (it can still be restored).', ['roles' => $su, 'icon' => 'trash']),
        'folder_unlocked' => W::ev('Restricted folder unlocked', 'A user enters their password to open a restricted folder.', ['roles' => $su, 'enabled' => false, 'icon' => 'lock-open']),
        'failed_unlock' => W::ev('Failed unlock attempts', 'A user gets their password wrong 3 times within 15 minutes when opening restricted content.', ['roles' => $su, 'icon' => 'exclamation']),
        'record_updated' => W::ev('Record updated', 'The details of an Archive record are edited (title, category, dates, project, tags and so on).', ['roles' => $su, 'enabled' => false, 'icon' => 'edit']),
        'record_restored' => W::ev('Record restored', 'A deleted Archive record is brought back.', ['roles' => $su, 'enabled' => false, 'icon' => 'refresh']),
        'file_added' => W::ev('File added to a record', 'A file is attached to an existing Archive record (files added while the record is first being created belong to "Any record created").', ['roles' => $su, 'enabled' => false, 'icon' => 'clip']),
        'file_removed' => W::ev('File removed from a record', 'A file is removed from an Archive record (it stays on disk and can be recovered).', ['roles' => $su, 'enabled' => false, 'icon' => 'trash']),
        'schedule_changed' => W::ev('Expiry summary schedule changed', 'The time of day or the number of days ahead of the daily expiry summary is changed on the Archive Notifications page.', ['roles' => $su, 'enabled' => false, 'icon' => 'cog']),
        'expiry_digest' => W::ev('Expiring / expired documents', 'A daily summary of documents that are about to expire or have expired. Each person is told only about the documents they may open.', ['roles' => $su, 'icon' => 'clock']),
    ],
    'tags' => [
        W::tag('reference', 'Record', 'Record number', 'The Archive record number.', 'ARC-0042'),
        W::tag('category', 'Record', 'Category', 'The category of the record.', 'Contracts'),
        W::tag('confidentiality', 'Record', 'Confidentiality', 'How confidential the record is.', 'Restricted'),
        W::tag('filename', 'Record', 'File name', 'The file that was opened or downloaded.', 'contract-signed.pdf'),
        W::tag('folder', 'Record', 'Folder', 'The restricted folder that was unlocked.', 'Legal'),
        W::tag('from', 'Change', 'Confidentiality before', 'Confidentiality before the change.', 'Internal'),
        W::tag('to', 'Change', 'Confidentiality after', 'Confidentiality after the change.', 'Restricted'),
        W::tag('misses', 'Change', 'Wrong passwords', 'How many wrong passwords were typed.', '3'),
        W::tag('expiring', 'Summary', 'Expiring soon', 'How many documents expire within the window (daily summary).', '4'),
        W::tag('expired', 'Summary', 'Already expired', 'How many documents have expired (daily summary).', '2'),
        W::tag('changes', 'Change', 'What was changed', 'The fields that were edited on the record.', 'title, category, expiry date'),
        W::tag('sendtime', 'Summary', 'Summary time', 'The time of day the daily summary is sent.', '08:00'),
        W::tag('days', 'Summary', 'Window (days)', 'The number of days ahead the summary looks.', '30'),
    ],
];
