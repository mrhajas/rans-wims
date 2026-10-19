<?php

use App\Support\Workflow\WorkflowModules as W;

$sms = fn (string $title, string $tail = '') => "WIMS Salary - {$title}\nPeriod: #period\n" . $tail;

// Every other action of the module: one event each. All are NEW and start switched off. [key, label, what it is, the employee is told too, icon]
$rows = [
    ['period_created', 'Salary Period Created', 'A new salary period is created.', false, 'plus'],
    ['period_deleted', 'Salary Period Deleted', 'A draft salary period is deleted.', false, 'trash'],
    ['period_cancelled', 'Salary Period Cancelled', 'An approved or partially paid period is cancelled, with the reason.', false, 'cross'],
    ['record_removed', 'Salary Record Removed', 'An employee\'s salary record is removed from a period that is still being prepared.', false, 'trash'],
    ['record_changed', 'Salary Record Changed', 'A one-off earning or deduction is added or removed, or an outstanding balance adjustment is recorded, on an employee\'s salary record.', false, 'edit'],
    ['bulk_paid', 'Salary Period Paid In Bulk', 'Every unpaid record of a period is paid in one action (each employee still gets their own payment notice).', false, 'cash'],
    ['payment_reversed', 'Salary Payment Reversed', 'A recorded salary payment is reversed, with the reason.', false, 'refresh'],
    ['vouchers_generated', 'Salary Vouchers Generated', 'A salary voucher is generated for one employee, or a bulk voucher for a whole period.', false, 'doc-text'],
    ['advance_created', 'Salary Advance Requested', 'A salary advance is recorded and waits for approval.', false, 'plus'],
    ['advance_updated', 'Salary Advance Edited', 'A pending salary advance is edited.', false, 'edit'],
    ['advance_deleted', 'Salary Advance Deleted', 'A pending salary advance is deleted.', false, 'trash'],
    ['advance_approved', 'Salary Advance Approved', 'A salary advance is approved. Switch on "the owner" to tell the employee.', true, 'tick'],
    ['advance_rejected', 'Salary Advance Rejected', 'A salary advance is rejected. Switch on "the owner" to tell the employee.', true, 'cross'],
    ['advance_cancelled', 'Salary Advance Cancelled', 'A salary advance is cancelled.', true, 'trash'],
    ['loan_created', 'Salary Loan Requested', 'A salary loan is recorded and waits for approval.', false, 'plus'],
    ['loan_updated', 'Salary Loan Edited', 'A pending salary loan is edited.', false, 'edit'],
    ['loan_deleted', 'Salary Loan Deleted', 'A pending salary loan is deleted.', false, 'trash'],
    ['loan_approved', 'Salary Loan Approved', 'A salary loan is approved. Switch on "the owner" to tell the employee.', true, 'tick'],
    ['loan_rejected', 'Salary Loan Rejected', 'A salary loan is rejected. Switch on "the owner" to tell the employee.', true, 'cross'],
    ['loan_cancelled', 'Salary Loan Cancelled', 'A salary loan is cancelled.', true, 'trash'],
    ['opening_balance_created', 'Opening Balance Recorded', 'An opening balance (unpaid salary, loan or advance brought forward) is recorded for one or many employees.', false, 'plus'],
    ['opening_balance_updated', 'Opening Balance Edited', 'An opening balance is edited.', false, 'edit'],
    ['opening_balance_deleted', 'Opening Balance Deleted', 'An opening balance is deleted.', false, 'trash'],
    ['opening_balance_applied', 'Opening Balance Applied', 'Unpaid salary brought forward is added to the employee\'s current salary record.', false, 'tick'],
    ['settings_changed', 'Salary Settings Changed', 'A salary component or the statutory settings are added, edited or removed.', false, 'cog'],
];
$extra = [];
foreach ($rows as [$k, $label, $desc, $owner, $icon]) {
    $extra[$k] = W::ev($label, $desc . ' New: off until switched on.', ['enabled' => false, 'icon' => $icon, 'owner' => $owner, 'roles' => ['Super Admin', 'Admin'],
        'subject' => $label, 'body' => '#message', 'sms_text' => "WIMS Salary - {$label}\n#message"]);
}

return [
    'key' => 'salary', 'path' => 'salary/workflow', 'page_route' => 'salary.workflow', 'update_route' => 'salary.workflow.update',
    'title' => 'Salary Alerts', 'subtitle' => 'Choose who gets notified about salary periods and payments, and how.',
    'record' => 'salary period', 'permission' => 'perm:manage-salary-settings',
    'owner_label' => 'the employee (for the payment notices)',
    'step_note' => 'Individual amounts stay private: the employee notice only tells them about their own payment.',
    'events' => [
        'prepared' => W::ev('Salary Prepared', 'A salary period is prepared.', ['roles' => ['Admin'], 'email' => true, 'sms_text' => $sms('Prepared', 'Prepared by: #actor'), 'subject' => 'Salary period #period prepared', 'body' => 'The salary period #period (#batch) was prepared by #actor.', 'icon' => 'doc-text']),
        'submitted' => W::ev('Salary Submitted for Approval', 'A prepared period is submitted for approval.', ['roles' => ['Admin'], 'email' => true, 'permission' => 'approve-salary-periods', 'step_owner' => false, 'sms_text' => $sms('Submitted', "Submitted by: #actor\nAwaiting approval."), 'subject' => 'Salary period #period submitted for approval', 'body' => 'The salary period #period (#batch) was submitted by #actor and is waiting for approval.', 'icon' => 'clip']),
        'approved' => W::ev('Salary Approved', 'A period is approved and ready for payment.', ['roles' => ['Admin'], 'email' => true, 'permission' => 'pay-salary-periods', 'step_owner' => false, 'sms_text' => $sms('Approved', "Approved by: #actor\nReady for payment."), 'subject' => 'Salary period #period approved', 'body' => 'The salary period #period (#batch) was approved by #actor and is ready for payment.', 'icon' => 'tick']),
        'rejected' => W::ev('Salary Rejected', 'A submitted period is rejected, with the reason.', ['roles' => ['Admin'], 'email' => true, 'sms_text' => $sms('Rejected', "Rejected by: #actor\nReason: #remarks"), 'subject' => 'Salary period #period rejected', 'body' => "The salary period #period (#batch) was rejected by #actor.\n#remarks", 'icon' => 'trash']),
        'paid' => W::ev('Salary Paid', 'The employee\'s own payment notice when their salary is paid in full.', ['owner' => true, 'email' => true, 'sms' => true, 'sms_text' => 'WIMS Salary: Your salary for #period (#amount) has been paid in full. Ref: #batch', 'subject' => 'Your salary for #period has been paid', 'body' => 'Your salary for #period has been paid in full.', 'icon' => 'cash']),
        'partially_paid' => W::ev('Partial Salary Payment', 'The employee\'s own notice when a partial payment is recorded, with the remaining balance.', ['owner' => true, 'email' => true, 'sms' => true, 'sms_text' => 'WIMS Salary: A partial payment of #amount was made for #period. Remaining balance: #remaining.', 'subject' => 'Partial salary payment for #period', 'body' => 'A partial salary payment of #amount for #period was recorded. Remaining balance: #remaining.', 'icon' => 'cash']),
    ] + $extra,
    'tags' => [
        W::tag('period', 'Salary', 'Period', 'The salary period.', 'October 2026'),
        W::tag('batch', 'Salary', 'Batch reference', 'The batch reference of the period.', 'SAL-BATCH-2026-10-0001'),
        W::tag('status', 'Salary', 'Current status', 'Where the period is now.', 'Approved'),
        W::tag('reference', 'Salary', 'Reference', 'The number of the advance, loan, opening balance or voucher the notice is about.', 'ADV-0012'),
        W::tag('employee', 'Salary', 'Employee', 'The employee (payment notices).', 'Fatma Ali'),
        W::tag('amount', 'Salary', 'Amount', 'The amount paid / payable (payment notices).', 'TZS 850,000'),
        W::tag('remaining', 'Salary', 'Remaining balance', 'What is still owed after a partial payment.', 'TZS 200,000'),
    ],
];
