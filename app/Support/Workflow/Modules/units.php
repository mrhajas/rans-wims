<?php

use App\Support\Workflow\WorkflowModules as W;

$off = ['enabled' => false, 'roles' => ['Super Admin', 'Admin'], 'bell' => true];

return [
    'key' => 'units', 'path' => 'units/workflow', 'page_route' => 'units.workflow', 'update_route' => 'units.workflow.update',
    'title' => 'Units Alerts', 'subtitle' => 'Decide who is told when a unit of measure is added, changed, restored, imported or deleted, on which channels and with which words. Nothing is sent until you switch an event on.',
    'record' => 'unit', 'owner_label' => 'nobody in particular',
    'events' => [
        'created' => W::ev('Unit Created', 'A new unit of measure is added.', $off + ['icon' => 'plus']),
        'updated' => W::ev('Unit Edited', 'A unit of measure is edited and saved.', $off + ['icon' => 'edit']),
        'deleted' => W::ev('Unit Deleted', 'A unit of measure is deleted (or removed for good).', $off + ['icon' => 'trash']),
        'restored' => W::ev('Unit Restored', 'A deleted unit is restored.', $off + ['icon' => 'refresh']),
        'imported' => W::ev('Units Imported', 'A spreadsheet of units is imported (one notice for the whole file, with the number of rows).', $off + ['icon' => 'in', 'subject' => 'Units imported', 'body' => '#actor imported #count units.']),
    ],
    'tags' => [
        W::tag('name', 'Unit', 'Unit name', 'The name of the unit.', 'Kilogram'),
        W::tag('code', 'Unit', 'Unit code', 'The short code of the unit (empty if none).', 'kg'),
        W::tag('baseunit', 'Unit', 'Base unit', 'The unit it is counted in (empty for a base unit).', 'Tonne'),
        W::tag('count', 'Unit', 'Rows imported', 'How many rows the spreadsheet had (import only).', '25'),
    ],
];
