<?php

use App\Support\Workflow\WorkflowModules as W;

// Funds Allocation told people on the app bell only, each by its own rule in code. The starting values below are exactly that:
// "the owner" of every event is the person the event is about (the payer, the preparer, the approver ...), named in the description.
$new = ['roles' => ['Super Admin', 'Admin'], 'enabled' => false];

return [
    'key' => 'fund_allocations', 'path' => 'fund-allocations/workflow', 'page_route' => 'fund-allocations.workflow', 'update_route' => 'fund-allocations.workflow.update',
    'title' => 'Funds Allocation Alerts', 'subtitle' => 'Decide who is told at each stage of an allocation sheet, on which channels and with which words.',
    'record' => 'allocation sheet', 'owner_label' => 'the person the notice is about (the payer, the preparer or the approver, as the event says)',
    'step_note' => 'A sheet is submitted, approved by someone with the approve-fund-allocations permission (who also chooses the payer), paid and ticked off by the payer, then cleared. The permissions are set under Roles.',
    'events' => [
        'approval_needed' => W::ev('Allocation Awaiting Approval', 'A sheet is submitted for approval: everyone allowed to approve Funds Allocations is told.', ['permission' => 'approve-fund-allocations', 'icon' => 'clock']),
        'pay' => W::ev('Allocation to Pay', 'A sheet is approved (or the payer is changed) and a person becomes the payer: the payer is told. The owner is the new payer.', ['owner' => true, 'icon' => 'cash']),
        'approved' => W::ev('Allocation Approved', 'A sheet is approved: the person who prepared it is told who the payer is. The owner is the preparer (not told twice if the preparer is also the payer).', ['owner' => true, 'icon' => 'tick']),
        'rejected' => W::ev('Allocation Rejected', 'A sheet is rejected with a reason: the person who prepared it is told. The owner is the preparer.', ['owner' => true, 'icon' => 'cross']),
        'reassigned' => W::ev('Allocation Reassigned', 'The payer is changed: the earlier payer is told they are no longer the payer. The owner is the earlier payer.', ['owner' => true, 'icon' => 'refresh']),
        'cancelled' => W::ev('Allocation Cancelled', 'A sheet is cancelled with a reason: the preparer and the payer are told. The owner is the preparer and the payer.', ['owner' => true, 'icon' => 'trash']),
        'vouchers_needed' => W::ev('Generate Payment Vouchers', 'The payer marks payments as paid: the preparer and everyone who raises Payment Vouchers are told to generate the vouchers (if nobody holds the create-payment-vouchers permission, the Super Admins and Admins are told instead).', ['owner' => true, 'permission' => 'create-payment-vouchers', 'icon' => 'doc-text']),
        'payment_vouchers_awaiting_approval' => W::ev('Payment Vouchers Awaiting Approval', 'Payment Vouchers are generated from a sheet: everyone allowed to approve Payment Vouchers is told (if nobody holds that permission, the Super Admins are told instead, as the owner).', ['owner' => true, 'permission' => 'approve-payment-vouchers', 'icon' => 'clock']),
        'completed' => W::ev('Allocation Completed', 'Every payment of a sheet has been ticked: the approver and the preparer are told. The owner is the approver and the preparer.', ['owner' => true, 'icon' => 'tick']),
        'cleared' => W::ev('Allocation Cleared', 'A fully paid sheet is closed and cleared: the approver and the preparer are told. The owner is the approver and the preparer.', ['owner' => true, 'icon' => 'tick']),
        // new events: switched off until a Super Admin turns them on
        'created' => W::ev('Allocation Created', 'A new allocation sheet is saved as a draft. New: off until switched on.', $new + ['subject' => 'Allocation #reference created', 'body' => 'Allocation sheet #reference (#sheettitle) was created by #actor.', 'sms_text' => "WIMS - Allocation created\n#reference\nBy: #actor", 'icon' => 'plus']),
        'updated' => W::ev('Allocation Edited', 'A draft, rejected or (by a Super Admin) approved allocation sheet is edited. The preparer is told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Allocation #reference edited', 'body' => 'Allocation sheet #reference (#sheettitle) was edited by #actor.', 'sms_text' => "WIMS - Allocation edited\n#reference\nBy: #actor", 'icon' => 'edit']),
        'deleted' => W::ev('Allocation Deleted', 'A draft, rejected or cancelled allocation sheet is deleted. The preparer is told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Allocation #reference deleted', 'body' => 'Allocation sheet #reference (#sheettitle) was deleted by #actor.', 'sms_text' => "WIMS - Allocation deleted\n#reference\nBy: #actor", 'icon' => 'trash']),
        'duplicated' => W::ev('Allocation Duplicated', 'A sheet is copied into a new draft (needs a Super Admin password). New: off until switched on.', $new + ['subject' => 'Allocation #reference duplicated', 'body' => 'Allocation sheet #reference was created as a copy by #actor. #message', 'sms_text' => "WIMS - Allocation duplicated\n#reference\nBy: #actor", 'icon' => 'plus']),
        'reopened' => W::ev('Allocation Reopened', 'A rejected or cancelled sheet is reopened as a draft by a Super Admin. The preparer is told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Allocation #reference reopened', 'body' => 'Allocation sheet #reference (#sheettitle) was reopened as a draft by #actor.', 'sms_text' => "WIMS - Allocation reopened\n#reference\nBy: #actor", 'icon' => 'refresh']),
        'payment_ticked' => W::ev('Payment Ticked As Paid', 'The payer ticks one payment (or several at once) as paid. The preparer and the approver are told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Payment ticked on #reference', 'body' => '#message', 'sms_text' => "WIMS - Payment ticked\n#reference\nBy: #actor", 'icon' => 'cash']),
        'payment_unticked' => W::ev('Payment Tick Removed', 'A payment tick is removed again (the payment is no longer marked as paid). The preparer and the approver are told too. New: off until switched on.', $new + ['owner' => true, 'subject' => 'Payment tick removed on #reference', 'body' => '#message', 'sms_text' => "WIMS - Payment tick removed\n#reference\nBy: #actor", 'icon' => 'cross']),
        'voucher_linked' => W::ev('Payment Voucher Linked', 'A payment voucher is linked to (or unlinked from) a payment of a sheet. New: off until switched on.', $new + ['subject' => 'Voucher linked on #reference', 'body' => '#message', 'sms_text' => "WIMS - Voucher link changed\n#reference\nBy: #actor", 'icon' => 'link']),
        'template_changed' => W::ev('Allocation Template Saved', 'The standard allocation template is saved. New: off until switched on.', $new + ['subject' => 'Allocation template saved', 'body' => '#message', 'sms_text' => "WIMS - Allocation template saved\nBy: #actor", 'icon' => 'doc-text']),
        'template_generated' => W::ev('Draft Generated From The Template', 'A draft sheet is generated from the template. New: off until switched on.', $new + ['subject' => 'Draft #reference generated from the template', 'body' => '#message', 'sms_text' => "WIMS - Draft generated\n#reference\nBy: #actor", 'icon' => 'plus']),
        'settings_changed' => W::ev('Funds Allocation Settings Changed', 'A fund account, category or payment method is added, edited, deleted or re-ordered. New: off until switched on.', $new + ['subject' => 'Funds Allocation settings changed', 'body' => '#message', 'sms_text' => "WIMS - Funds Allocation settings changed\nBy: #actor", 'icon' => 'cog']),
    ],
    'tags' => [
        W::tag('reference', 'Allocation', 'Sheet number', 'The reference of the allocation sheet.', 'FA-0042'),
        W::tag('sheettitle', 'Allocation', 'Sheet title', 'The title typed on the sheet (the date if it has none).', 'October payments'),
        W::tag('date', 'Allocation', 'Allocation date', 'The date of the sheet.', '12 Oct 2026'),
        W::tag('preparer', 'Allocation', 'Prepared by', 'Who prepared the sheet.', 'Salha Shaaban'),
        W::tag('approver', 'Allocation', 'Approved by', 'Who approved the sheet (empty before approval).', 'Abdulhakeem Nassir'),
        W::tag('payer', 'Allocation', 'Payer', 'Who pays the sheet (empty before approval).', 'Flow PM'),
        W::tag('what', 'Settings', 'What changed', 'The setting that was changed (a fund account, category, payment method or the order).', 'Payment method "Cash"'),
        W::tag('change', 'Settings', 'How', 'What was done to it (added, updated, deleted, moved up).', 'added'),
        W::tag('status', 'Allocation', 'Current status', 'Where the sheet is now.', 'In Progress'),
    ],
];
