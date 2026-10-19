<?php

use App\Support\Workflow\WorkflowModules as W;

$staff = ['Super Admin', 'Admin', 'Project Manager'];

return [
    'key' => 'issue_returns', 'path' => 'issue-returns/workflow', 'page_route' => 'issue-returns.workflow', 'update_route' => 'issue-returns.workflow.update',
    'title' => 'Issue & Return Alerts', 'subtitle' => 'Decide who is told when material is issued, returned, lost or damaged, on which channels and with which words.',
    'record' => 'issue', 'owner_label' => 'the person who made the issue',
    'events' => [
        'issue_created' => W::ev('New Issue Created', 'Material is issued to a person or team (a new issue is saved).', ['roles' => $staff, 'project' => false, 'icon' => 'plus']),
        'item_returned' => W::ev('Item Returned', 'A return is posted against an issue. This general notice is used for a return when its more specific notice (Partial Return, Fully Returned) is switched off.', ['roles' => $staff, 'icon' => 'in']),
        'partial_return' => W::ev('Partial Return', 'A return is posted and part of the issued material is still out.', ['roles' => $staff, 'icon' => 'in']),
        'fully_returned' => W::ev('Fully Returned', 'A return is posted and everything issued has now come back.', ['roles' => $staff, 'icon' => 'tick']),
        'lost_or_damaged' => W::ev('Lost or Damaged Items Returned', 'A return contains lines marked lost or damaged.', ['roles' => ['Super Admin', 'Admin'], 'icon' => 'exclamation']),
        'updated' => W::ev('Issue Edited', 'An issue is edited and saved (only possible before any return).', ['enabled' => false, 'roles' => ['Super Admin', 'Admin'], 'owner' => true, 'bell' => true, 'icon' => 'edit']),
        'deleted' => W::ev('Issue Deleted', 'An issue is deleted and its stock is put back.', ['enabled' => false, 'roles' => ['Super Admin', 'Admin'], 'icon' => 'trash']),
    ],
    'tags' => [
        W::tag('reference', 'Issue', 'Issue number', 'The issue transaction number.', 'ISS-0042'),
        W::tag('issuedto', 'Issue', 'Issued to', 'The person or team the material was issued to.', 'Fundi Hamisi'),
        W::tag('project', 'Issue', 'Project', 'The project the material was issued for.', 'MOMBASA MARKET'),
        W::tag('warehouse', 'Issue', 'Warehouse', 'The warehouse the material left from.', 'Main Store'),
        W::tag('department', 'Issue', 'Department', 'The department of the receiver (if typed).', 'Electrical'),
        W::tag('status', 'Issue', 'Status', 'The status of the issue.', 'partially_returned'),
        W::tag('expectedreturn', 'Issue', 'Expected return date', 'The date the material should be back (if set).', '20 Oct 2026'),
        W::tag('creator', 'Issue', 'Issued by', 'Who made the issue.', 'Salha Shaaban'),
    ],
];
