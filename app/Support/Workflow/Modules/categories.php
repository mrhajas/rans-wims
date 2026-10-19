<?php

use App\Support\Workflow\WorkflowModules as W;

$off = ['enabled' => false, 'roles' => ['Super Admin', 'Admin'], 'bell' => true];

return [
    'key' => 'categories', 'path' => 'categories/workflow', 'page_route' => 'categories.workflow', 'update_route' => 'categories.workflow.update',
    'title' => 'Categories Alerts', 'subtitle' => 'Decide who is told when an item category is added, changed, restored, imported or deleted, on which channels and with which words. Nothing is sent until you switch an event on.',
    'record' => 'category', 'owner_label' => 'nobody in particular',
    'events' => [
        'created' => W::ev('Category Created', 'A new item category is added.', $off + ['icon' => 'plus']),
        'updated' => W::ev('Category Edited', 'An item category is edited and saved.', $off + ['icon' => 'edit']),
        'deleted' => W::ev('Category Deleted', 'An item category is deleted (or removed for good).', $off + ['icon' => 'trash']),
        'restored' => W::ev('Category Restored', 'A deleted category is restored.', $off + ['icon' => 'refresh']),
        'imported' => W::ev('Categories Imported', 'A spreadsheet of categories is imported (one notice for the whole file, with the number of rows).', $off + ['icon' => 'in', 'subject' => 'Categories imported', 'body' => '#actor imported #count categories.']),
    ],
    'tags' => [
        W::tag('name', 'Category', 'Category name', 'The name of the category.', 'Cement and Binders'),
        W::tag('code', 'Category', 'Category code', 'The code of the category (empty if none).', 'CEM'),
        W::tag('parent', 'Category', 'Parent category', 'The category it sits under (empty for a main category).', 'Building Materials'),
        W::tag('count', 'Category', 'Rows imported', 'How many rows the spreadsheet had (import only).', '25'),
    ],
];
