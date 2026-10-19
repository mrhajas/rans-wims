<?php

use App\Support\Workflow\WorkflowModules as W;

$hr = ['roles' => ['Super Admin', 'Admin'], 'enabled' => false];

return [
    'key' => 'staff', 'path' => 'staffs/workflow', 'page_route' => 'staffs.workflow', 'update_route' => 'staffs.workflow.update',
    'title' => 'Staff Alerts', 'subtitle' => 'Decide who is told about staff records, contracts, retirement and login links, on which channels and with which words.',
    'record' => 'staff record', 'owner_label' => 'the staff member (when they have a login)', 'permission' => 'perm:update-staffs',
    'events' => [
        // staff record
        'created' => W::ev('Staff Added', 'A new staff record is created.', $hr + ['icon' => 'plus', 'subject' => 'Staff added: #staff', 'body' => '#staff (#staffnumber) was added by #actor.', 'sms_text' => "WIMS - Staff added\n#staff (#staffnumber)\nBy: #actor"]),
        'updated' => W::ev('Staff Details Updated', 'A staff record is edited (details, bank, position...).', $hr + ['icon' => 'edit', 'subject' => 'Staff updated: #staff', 'body' => '#staff (#staffnumber) was updated by #actor.', 'sms_text' => "WIMS - Staff updated\n#staff (#staffnumber)\nBy: #actor"]),
        'status_changed' => W::ev('Staff Status Changed', 'A staff status changes in the edit form (Active, Inactive, Terminated).', $hr + ['icon' => 'refresh', 'subject' => 'Staff status changed: #staff', 'body' => '#staff (#staffnumber) is now #status. Changed by #actor.', 'sms_text' => "WIMS - Staff status\n#staff is now #status\nBy: #actor"]),
        'salary_changed' => W::ev('Staff Pay Changed', 'The basic salary, allowances or deductions of a staff member are changed.', $hr + ['icon' => 'cash', 'subject' => 'Pay changed: #staff', 'body' => 'The pay of #staff (#staffnumber) was changed by #actor.', 'sms_text' => "WIMS - Staff pay changed\n#staff (#staffnumber)\nBy: #actor"]),
        'deleted' => W::ev('Staff Deleted', 'A staff record is deleted.', $hr + ['icon' => 'trash', 'subject' => 'Staff deleted: #staff', 'body' => '#staff (#staffnumber) was deleted by #actor.', 'sms_text' => "WIMS - Staff deleted\n#staff (#staffnumber)\nBy: #actor"]),
        'retired' => W::ev('Staff Retired', 'A staff member is marked Retired or Retired Active (still on payroll).', $hr + ['icon' => 'users', 'subject' => 'Staff retired: #staff', 'body' => '#staff (#staffnumber) is now #status. Marked by #actor.', 'sms_text' => "WIMS - Staff retired\n#staff is now #status\nBy: #actor"]),
        'reinstated' => W::ev('Staff Reinstated', 'A retired staff member is made Active again.', $hr + ['icon' => 'tick', 'subject' => 'Staff reinstated: #staff', 'body' => '#staff (#staffnumber) is Active again. Done by #actor.', 'sms_text' => "WIMS - Staff reinstated\n#staff (#staffnumber)\nBy: #actor"]),
        'photo_removed' => W::ev('Staff Photo Removed', 'The photo of a staff member is removed.', $hr + ['icon' => 'camera', 'subject' => 'Staff photo removed: #staff', 'body' => 'The photo of #staff (#staffnumber) was removed by #actor.', 'sms_text' => "WIMS - Staff photo removed\n#staff (#staffnumber)\nBy: #actor"]),
        'document_removed' => W::ev('Staff Document Removed', 'A file attached to a staff record or to one of its contracts is removed.', $hr + ['icon' => 'clip', 'subject' => 'Staff document removed: #staff', 'body' => 'The file #file of #staff (#staffnumber) was removed by #actor.', 'sms_text' => "WIMS - Staff document removed\n#staff (#staffnumber)\nFile: #file\nBy: #actor"]),
        // contracts
        'contract_added' => W::ev('Contract Added', 'A contract is added to a staff member.', $hr + ['icon' => 'doc-text', 'subject' => 'Contract added: #staff', 'body' => 'Contract #contract (#contracttype) was added for #staff by #actor. It runs from #starts to #ends.', 'sms_text' => "WIMS - Contract added\n#staff\n#contract (#contracttype)\nBy: #actor"]),
        'contract_updated' => W::ev('Contract Updated', 'A contract is edited.', $hr + ['icon' => 'edit', 'subject' => 'Contract updated: #staff', 'body' => 'Contract #contract of #staff was updated by #actor.', 'sms_text' => "WIMS - Contract updated\n#staff\n#contract\nBy: #actor"]),
        'contract_renewed' => W::ev('Contract Renewed', 'A contract is renewed as a new contract.', $hr + ['icon' => 'refresh', 'subject' => 'Contract renewed: #staff', 'body' => 'The contract of #staff was renewed as #contract by #actor. It runs from #starts to #ends.', 'sms_text' => "WIMS - Contract renewed\n#staff\nNew: #contract\nBy: #actor"]),
        'contract_stopped' => W::ev('Contract Stopped', 'A contract is stopped before its end date, with a reason.', $hr + ['icon' => 'cross', 'subject' => 'Contract stopped: #staff', 'body' => 'Contract #contract of #staff was stopped by #actor. Reason: #reason', 'sms_text' => "WIMS - Contract stopped\n#staff\n#contract\nBy: #actor"]),
        'contract_deleted' => W::ev('Contract Deleted', 'A contract is deleted.', $hr + ['icon' => 'trash', 'subject' => 'Contract deleted: #staff', 'body' => 'Contract #contract of #staff was deleted by #actor.', 'sms_text' => "WIMS - Contract deleted\n#staff\n#contract\nBy: #actor"]),
        'contract_ending' => W::ev('Staff Contract Ending', 'A staff contract is about to end (60, 30, 14, 7 and 1 days before, on the day, and once it has run out) so it can be renewed in time.', ['roles' => ['Super Admin', 'Admin'], 'icon' => 'users', 'aliases' => ['nc:staff-contract-ending']]),
        // login links
        'linked' => W::ev('Staff Linked To A Login', 'A staff profile is linked to a user login.', $hr + ['icon' => 'users', 'subject' => 'Staff linked: #staff', 'body' => '#staff (#staffnumber) was linked to the login of #user by #actor.', 'sms_text' => "WIMS - Staff linked\n#staff to #user\nBy: #actor"]),
        'created_from_user' => W::ev('Staff Created From A Login', 'A staff profile is created from an existing user login.', $hr + ['icon' => 'plus', 'subject' => 'Staff created from a login: #staff', 'body' => 'A staff profile for #staff (#staffnumber) was created from the login of #user by #actor.', 'sms_text' => "WIMS - Staff created from login\n#staff\nBy: #actor"]),
        'unlinked' => W::ev('Staff Unlinked From A Login', 'A staff profile is unlinked from its user login.', $hr + ['icon' => 'cross', 'subject' => 'Staff unlinked: #staff', 'body' => '#staff (#staffnumber) was unlinked from the login of #user by #actor.', 'sms_text' => "WIMS - Staff unlinked\n#staff from #user\nBy: #actor"]),
    ],
    'tags' => [
        W::tag('staff', 'Staff member', 'Staff member', 'The name of the employee.', 'Fatma Ali'),
        W::tag('staffnumber', 'Staff member', 'Staff number', 'The staff number.', 'STF-0007'),
        W::tag('position', 'Staff member', 'Position', 'The job position.', 'Site Engineer'),
        W::tag('status', 'Staff member', 'Status', 'The staff status now (Active, Inactive, Terminated, Retired, Retired Active).', 'Retired Active'),
        W::tag('user', 'Staff member', 'Linked login', 'The user login the profile is linked to (empty if none).', 'Fatma Ali'),
        W::tag('contract', 'Contract', 'Contract number', 'The contract number.', 'CTR-0007'),
        W::tag('contracttype', 'Contract', 'Contract type', 'The type of contract.', 'Fixed term'),
        W::tag('starts', 'Contract', 'Start date', 'When the contract starts.', '01 Dec 2026'),
        W::tag('ends', 'Contract', 'End date', 'When the contract ends.', '30 Nov 2027'),
        W::tag('file', 'Staff member', 'File name', 'The name of the file that was removed (document removal only).', 'contract-signed.pdf'),
        W::tag('reason', 'Contract', 'Reason', 'Why a contract was stopped (empty for other events).', 'Resigned'),
    ],
];
