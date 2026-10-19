<?php

use App\Support\Workflow\WorkflowModules as W;

$off = ['enabled' => false, 'roles' => ['Super Admin', 'Admin'], 'bell' => true];

return [
    'key' => 'contacts', 'path' => 'contacts/workflow', 'page_route' => 'contacts.workflow', 'update_route' => 'contacts.workflow.update',
    'title' => 'Contacts Alerts', 'subtitle' => 'Decide who is told when a contact is added, changed, restored, imported or deleted, on which channels and with which words. Nothing is sent until you switch an event on.',
    'record' => 'contact', 'owner_label' => 'nobody in particular',
    'events' => [
        'created' => W::ev('Contact Created', 'A new contact is added.', $off + ['icon' => 'plus']),
        'updated' => W::ev('Contact Edited', 'A contact is edited and saved.', $off + ['icon' => 'edit']),
        'deleted' => W::ev('Contact Deleted', 'A contact is deleted (or removed for good).', $off + ['icon' => 'trash']),
        'restored' => W::ev('Contact Restored', 'A deleted contact is restored.', $off + ['icon' => 'refresh']),
        'imported' => W::ev('Contacts Imported', 'A spreadsheet of contacts is imported (one notice for the whole file, with the number of rows).', $off + ['icon' => 'in', 'subject' => 'Contacts imported', 'body' => '#actor imported #count contacts.']),
    ],
    'tags' => [
        W::tag('name', 'Contact', 'Contact name', 'The name of the contact.', 'Fundi Hamisi'),
        W::tag('category', 'Contact', 'Category', 'The category of the contact.', 'Technicians'),
        W::tag('phone', 'Contact', 'Phone', 'The phone number of the contact (empty if none).', '0755 000 111'),
        W::tag('count', 'Contact', 'Rows imported', 'How many rows the spreadsheet had (import only).', '25'),
    ],
];
