<?php

use App\Support\Workflow\WorkflowModules as W;

// Accounts Tracking: who is told about money events, on which channels and with which words. The Alerts LIST (every alert, with acknowledge / resolve)
// is its own page; this one only decides who is notified. Amounts and account names only ever go to people allowed to see them.
$sms = fn (string $t) => "WIMS Accounts - {$t}\n#message";

return [
    'key' => 'accounts_tracking', 'path' => 'accounts-tracking/workflow', 'page_route' => 'acct.workflow', 'update_route' => 'acct.workflow.update',
    'title' => 'Accounts Tracking Alerts', 'subtitle' => 'Decide who is told about approvals, large payments, low balances, reconciliation problems, exchange rates and security events, on which channels and with which words.',
    'record' => 'financial record', 'owner_label' => 'the person who created the transaction',
    'step_note' => 'The people who hold the matching approve ability (approve-acct-inflows, approve-acct-outflows, approve-acct-transfers or approve-acct-adjustments, set under Roles) are told about what is waiting for their approval; account custodians and the alert recipients of an account are added automatically for balance alerts.',
    'events' => [
        'approval_needed' => W::ev('Waiting for Approval', 'A transaction or transfer is submitted and needs approval: everyone allowed to approve is told.', ['roles' => ['Super Admin'], 'icon' => 'clock', 'sms_text' => $sms('Approval needed')]),
        'large_transaction' => W::ev('Large Transaction', 'A transaction at or above the large-transaction limit (Settings) is submitted.', ['roles' => ['Super Admin'], 'icon' => 'exclamation', 'sms_text' => $sms('Large transaction')]),
        'unusual_transaction' => W::ev('Unusual Transaction', 'A posted payment is far above the account\'s recent average, or looks like a duplicate.', ['roles' => ['Super Admin'], 'icon' => 'exclamation']),
        'balance_alert' => W::ev('Account Balance Alert', 'An account falls below its minimum, goes above its maximum cash holding, or goes negative. The account custodian and its alert recipients are told too.', ['roles' => ['Super Admin'], 'owner' => true, 'icon' => 'cash', 'sms_text' => $sms('Balance alert')]),
        'reconciliation_alert' => W::ev('Reconciliation Alert', 'A reconciliation is overdue, a cash count or bank statement does not agree, or an item is unmatched.', ['roles' => ['Super Admin'], 'permission' => 'approve-acct-reconciliations', 'icon' => 'tick']),
        'rate_alert' => W::ev('Exchange Rate Alert', 'A rate is missing, waiting for approval, expired or moved unusually.', ['permission' => 'approve-acct-rates', 'roles' => ['Super Admin'], 'icon' => 'refresh']),
        'security_alert' => W::ev('Security Alert', 'Approval without permission, repeated failed posting, or another attempt that breaks the financial rules.', ['roles' => ['Super Admin'], 'icon' => 'lock-closed', 'sms_text' => $sms('Security alert')]),
    ],
    'tags' => [
        W::tag('reference', 'Record', 'Reference', 'The alert, transaction or transfer reference.', 'ACT-2026-000042'),
        W::tag('account', 'Record', 'Account', 'The account the alert is about.', 'PBZ - Main TZS'),
        W::tag('amount', 'Record', 'Amount', 'The amount and its currency.', 'TZS 5,000,000.00'),
    ],
];
