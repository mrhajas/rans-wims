<?php

use App\Support\Workflow\WorkflowModules as W;

$off = ['enabled' => false, 'roles' => ['Super Admin', 'Admin'], 'bell' => true];

return [
    'key' => 'assets', 'path' => 'company-assets/workflow', 'page_route' => 'assets.workflow', 'update_route' => 'assets.workflow.update',
    'title' => 'Assets Alerts', 'subtitle' => 'Decide who is told when an asset warranty, insurance, registration, inspection or licence is about to expire, and when an asset is added, changed, moved, assigned, maintained, valued, disposed of, deleted or turned into an item, or its documents and categories change, on which channels and with which words.',
    'record' => 'asset', 'owner_label' => 'the person the asset is assigned to',
    'step_note' => 'The expiry reminder goes to the person the asset is assigned to and to everyone who may view asset financials (set under Roles). A person who switched off asset expiry emails in their own profile still gets the bell notice but no email.',
    'events' => [
        'expiry_reminder' => W::ev('Asset Expiry Reminder', 'A warranty, insurance, registration, inspection or licence date is 30, 14, 7 or 1 day(s) away (checked once a day at 07:15).', ['permission' => 'view-asset-financials', 'owner' => true, 'email' => true, 'icon' => 'clock']),
        'created' => W::ev('Asset Registered', 'A new asset is added to the Asset Register.', $off + ['icon' => 'plus']),
        'deleted' => W::ev('Asset Deleted', 'An asset is deleted.', $off + ['icon' => 'trash']),
        'converted_to_item' => W::ev('Asset Converted to Item', 'An asset is turned into a catalogue Item.', $off + ['icon' => 'refresh']),
        // register
        'updated' => W::ev('Asset Details Updated', 'An asset is edited and saved (details, location, holder, quantity, photos).', $off + ['icon' => 'edit', 'owner' => true]),
        'status_changed' => W::ev('Asset Status Changed', 'The status of an asset is changed (for example Available, Under Repair, Lost).', $off + ['icon' => 'refresh', 'owner' => true]),
        'assigned' => W::ev('Asset Assigned', 'An asset is assigned to a person, team or site; the person it is assigned to is told when they have a login.', $off + ['icon' => 'users', 'owner' => true]),
        // movements
        'checked_out' => W::ev('Asset Checked Out', 'An asset (or part of its quantity) is checked out to a place or a responsible person.', $off + ['icon' => 'out', 'owner' => true]),
        'checked_in' => W::ev('Asset Checked In', 'An asset (or part of its quantity) is checked back in, with its condition.', $off + ['icon' => 'in']),
        'transferred' => W::ev('Asset Transferred', 'An asset (or part of its quantity) is transferred to another place or responsible person.', $off + ['icon' => 'truck', 'owner' => true]),
        'disposed' => W::ev('Asset Disposed', 'An asset is disposed of: sold, scrapped, written off, lost or stolen.', $off + ['icon' => 'trash']),
        // maintenance and value
        'maintenance_added' => W::ev('Maintenance Recorded', 'A maintenance or service record is added to an asset.', $off + ['icon' => 'cog']),
        'maintenance_updated' => W::ev('Maintenance Record Updated', 'A maintenance record of an asset is edited.', $off + ['icon' => 'edit']),
        'maintenance_deleted' => W::ev('Maintenance Record Deleted', 'A maintenance record of an asset is deleted.', $off + ['icon' => 'trash']),
        'depreciation_posted' => W::ev('Depreciation Posted', 'Depreciation for a period is posted on an asset.', $off + ['icon' => 'chart-bar']),
        'appreciation_posted' => W::ev('Appreciation Posted', 'A new (higher) value for a period is posted on an asset.', $off + ['icon' => 'chart-bar']),
        // documents
        'document_added' => W::ev('Asset Document Uploaded', 'A document is uploaded to an asset.', $off + ['icon' => 'clip']),
        'document_removed' => W::ev('Asset Document Removed', 'A document of an asset is removed.', $off + ['icon' => 'clip']),
        // categories
        'category_created' => W::ev('Asset Category Created', 'A new asset category is added.', $off + ['icon' => 'plus']),
        'category_updated' => W::ev('Asset Category Edited', 'An asset category is renamed, moved or switched on or off.', $off + ['icon' => 'edit']),
        'category_deleted' => W::ev('Asset Category Deleted', 'An asset category is deleted.', $off + ['icon' => 'trash']),
    ],
    'tags' => [
        W::tag('asset', 'Asset', 'Asset name', 'The name of the asset.', 'CAT 320 Excavator'),
        W::tag('assetnumber', 'Asset', 'Asset number', 'The asset register number.', 'AST-0042'),
        W::tag('expiring', 'Asset', 'What is expiring', 'Warranty, Insurance, Registration, Inspection or License.', 'Insurance'),
        W::tag('days', 'Asset', 'Days left', 'How many days remain before the date.', '7'),
        W::tag('expirydate', 'Asset', 'Expiry date', 'The date it expires.', '19 Oct 2026'),
        W::tag('assignedto', 'Asset', 'Assigned to', 'The person the asset is assigned to (empty if nobody).', 'Hamisi Juma'),
        W::tag('status', 'Asset', 'Status', 'The status of the asset after this action.', 'Under Repair'),
        W::tag('location', 'Asset', 'Place', 'Where the asset (or the part moved) is now: the project / warehouse or location.', 'Mombasa Market'),
        W::tag('quantity', 'Asset', 'Quantity', 'How many pieces were moved.', '2'),
        W::tag('kind', 'Asset', 'Type / method', 'The kind of maintenance, or the way the asset was disposed of.', 'Scheduled service'),
        W::tag('amount', 'Asset', 'Amount', 'The cost, the value or the depreciation posted (TZS).', 'TZS 450,000'),
        W::tag('period', 'Asset', 'Period', 'The period of the depreciation or appreciation.', '01 Jan 2026 to 31 Dec 2026'),
        W::tag('file', 'Asset', 'Document', 'The type or name of the document added or removed.', 'Insurance Certificate'),
        W::tag('category', 'Asset', 'Category name', 'The asset category the notice is about (category events only).', 'Heavy Equipment'),
        W::tag('item', 'Asset', 'New item code', 'The code of the item created from the asset (conversion only).', 'RSI-0123'),
    ],
];
