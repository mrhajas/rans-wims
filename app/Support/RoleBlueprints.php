<?php

namespace App\Support;

use App\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Starting permissions for the office roles (Accounts, Chief Accountant, Cashier, Administration, Receptionist). They are a first guess, chosen from what each
 * job does, and are meant to be adjusted on the Roles screen. Every role also gets the "everyone" set (messages, own tasks and reminders, attendance, notices,
 * weather, QR scan, plus view-only lookups of warehouses, items, units, categories and contacts) and the "All warehouses" setting.
 *
 * apply() only ever ADDS: a role that already exists keeps what it has (and its name), so running it again, or after someone edited a role, changes nothing that was taken away.
 * The Technician role already exists in this system and is not part of this list.
 */
class RoleBlueprints
{
    /** What every role gets. */
    public const EVERYONE = [
        // WIMSChat
        'view-wimschat', 'start-wimschat-conversations', 'send-wimschat-messages', 'edit-wimschat-messages', 'react-wimschat-messages', 'forward-wimschat-messages', 'pin-wimschat-messages',
        'search-wimschat', 'mention-wimschat-users', 'nudge-wimschat-users', 'attach-wimschat-files', 'view-wimschat-attachments', 'download-wimschat-attachments',
        // the person's own desk
        'customize-dashboard-cards', 'view-online-users', 'view-own-smart-brief', 'read-weather', 'scan-qr-codes', 'print-qr-codes', 'read-qr-scans', 'read-notices', 'use-ai-assistant',
        'read-task-reminders', 'create-task-reminders', 'update-task-reminders', 'delete-task-reminders',
        'read-digital-attendance', 'clock-in-digital-attendance', 'clock-out-digital-attendance',
        'read-task-manager', 'update-task-manager', 'comment-task-manager', 'mark-complete-task-manager', 'change-status-task-manager',
        'read-case-tracking', 'create-case-tracking', 'update-case-tracking', 'comment-case-tracking', 'attach-case-tracking',
        // view-only lookups the forms need
        'read-contacts', 'read-warehouses', 'read-items', 'read-units', 'read-categories',
    ];

    /** @return array<string,string[]> role name => permissions (besides EVERYONE) */
    public static function roles(): array
    {
        // the accounting desk work shared by Accounts and the Chief Accountant (prepare, never approve)
        $accounting = [
            'read-reports', 'read-material-value', 'read-movement-value', 'read-supplier-reports', 'read-acct-reports',
            // vouchers and supplier payments
            'read-payment-vouchers', 'create-payment-vouchers', 'update-payment-vouchers', 'print-payment-vouchers',
            'read-supplier-records', 'read-payment-requests', 'create-payment-requests', 'update-payment-requests', 'print-payment-requests',
            'read-supplier-invoices', 'create-supplier-invoices', 'update-supplier-invoices', 'print-supplier-invoices',
            'read-payments', 'create-payments', 'read-supplier-ledger', 'create-supplier-ledger',
            'read-purchase-orders', 'print-purchase-orders', 'read-goods-receipts', 'print-goods-receipts', 'read-quotations', 'print-quotations', 'read-purchase-requests',
            // money accounts
            'view-accounts-tracking', 'read-acct-accounts', 'read-acct-transactions', 'read-acct-transfers',
            'create-acct-inflows', 'create-acct-outflows', 'create-acct-transfers',
            // other documents that end in a payment
            'read-fund-allocations', 'print-fund-allocations', 'read-fund-allocation-reports',
            'read-weekly-administration', 'print-weekly-administration',
            'read-travels', 'print-travels', 'read-travel-agents', 'create-payment-voucher-from-travels',
            'read-transport-dashboard', 'read-transport-records', 'create-payment-voucher-from-transport',
            'create-payment-voucher-from-labour', 'read-labour', 'print-labour', 'read-workforce-agreements',
            'read-subcontracts', 'read-subcontract-agreements', 'read-subcontract-requests', 'read-subcontract-reports', 'print-subcontract-reports',
            // records for reference
            'read-checkins', 'read-checkouts', 'read-transfers', 'print-contacts', 'create-contacts', 'update-contacts',
            'read-staffs', 'read-assets', 'view-asset-financials', 'print-assets',
            'read-delivery-notes', 'read-gate-passes', 'read-archive',
        ];

        return [
            'Accounts' => $accounting,

            'Chief Accountant' => array_merge($accounting, [
                // the approvals and the full books
                'approve-payment-vouchers', 'authorise-payment-vouchers', 'approve-payment-requests', 'approve-supplier-invoices', 'approve-purchase-orders', 'delete-payment-vouchers',
                'edit-supplier-ledger', 'update-supplier-records', 'view-all-acct-accounts', 'view-acct-sensitive', 'manage-acct-cash', 'manage-acct-banks',
                'create-acct-accounts', 'update-acct-accounts', 'approve-acct-inflows', 'post-acct-inflows', 'approve-acct-outflows', 'post-acct-outflows',
                'approve-acct-transfers', 'post-acct-transfers',
                'create-fund-allocations', 'update-fund-allocations', 'approve-fund-allocations', 'export-fund-allocations',
                'read-fund-allocation-templates', 'update-fund-allocation-templates', 'generate-fund-allocation-templates',
                'create-weekly-administration', 'update-weekly-administration', 'approve-weekly-administration', 'authorize-weekly-administration', 'view-all-weekly-administration',
                'send-weekly-administration', 'create-payment-voucher-from-administration',
                'verify-transport-records', 'manage-travel-agents', 'update-travels',
                // salary: prepared and approved here, paid by the Cashier
                'read-salary-periods', 'create-salary-periods', 'prepare-bulk-salary-periods', 'update-salary-periods', 'submit-salary-periods', 'approve-salary-periods', 'adjust-salary-periods',
                'view-salary-ledger', 'read-salary-vouchers', 'create-salary-vouchers', 'print-salary-vouchers',
                'read-salary-advances', 'create-salary-advances', 'update-salary-advances', 'approve-salary-advances', 'read-salary-loans', 'create-salary-loans', 'update-salary-loans', 'approve-salary-loans',
                'read-salary-opening-balances', 'create-salary-opening-balances', 'update-salary-opening-balances', 'read-salary-analytics', 'read-salary-reports', 'export-salary-reports',
                'view-financials-subcontracts', 'review-subcontract-requests', 'initiate-subcontract-vouchers', 'reconcile-subcontracts', 'read-opening-subcontracts',
                'record-asset-depreciation', 'record-asset-appreciation', 'export-assets',
                'view-compliance-financials', 'read-compliance-records',
            ]),

            'Cashier' => [
                'read-payment-vouchers', 'print-payment-vouchers', 'record-payment-payment-vouchers',
                'read-payments', 'create-payments', 'read-supplier-ledger',
                'view-accounts-tracking', 'read-acct-accounts', 'read-acct-transactions', 'read-acct-transfers', 'create-acct-inflows', 'create-acct-outflows', 'create-acct-transfers',
                'read-fund-allocations', 'pay-fund-allocations', 'print-fund-allocations',
                'read-weekly-administration', 'mark-paid-weekly-administration', 'print-weekly-administration',
                'read-salary-periods', 'pay-salary-periods', 'read-salary-vouchers', 'print-salary-vouchers',
                'read-transport-records', 'mark-paid-transport-records', 'read-travels', 'print-travels',
                'read-payment-requests', 'read-subcontract-requests', 'print-contacts',
            ],

            'Administration' => [
                // Visitor Logs: oversight (corrections, cancellations, the lists and the thresholds, the audit and the reports)
                'read-visitor-logs', 'view-all-visits', 'view-active-visitors', 'correct-visitor-logs', 'cancel-visitor-logs', 'read-visitors', 'view-visitor-history', 'read-incoming-items', 'correct-incoming-items', 'view-incoming-item-history',
                'read-visitor-reports', 'print-visitor-reports', 'view-visitor-audit', 'manage-visitor-options', 'manage-visitor-settings',
                // the weekly administration document (prepare and send; approval and payment stay with the accountants)
                'read-weekly-administration', 'create-weekly-administration', 'update-weekly-administration', 'duplicate-weekly-administration', 'print-weekly-administration',
                'send-weekly-administration', 'view-all-weekly-administration', 'cancel-weekly-administration', 'create-payment-voucher-from-administration',
                'read-payment-vouchers', 'create-payment-vouchers', 'print-payment-vouchers',
                'read-fund-allocations', 'print-fund-allocations',
                // travels, staff and company records
                'read-travels', 'create-travels', 'update-travels', 'print-travels', 'read-travel-agents',
                'read-staffs', 'create-staffs', 'update-staffs', 'print-staffs',
                'read-staff-contracts', 'create-staff-contracts', 'update-staff-contracts', 'print-staff-contracts',
                'read-digital-attendance', 'read-all-digital-attendance', 'read-digital-attendance-reports', 'export-digital-attendance',
                'read-compliance-records', 'create-compliance-records', 'update-compliance-records', 'assign-compliance-records', 'manage-compliance-reminders', 'upload-compliance-documents',
                'send-compliance-reminder', 'start-compliance-renewal', 'export-compliance-records',
                'read-assets', 'read-asset-dashboard', 'create-assets', 'update-assets', 'assign-assets', 'check-out-assets', 'check-in-assets', 'transfer-assets', 'record-asset-maintenance', 'print-assets', 'export-assets',
                'read-archive', 'create-archive', 'update-archive', 'preview-archive-files', 'download-archive-files', 'print-archive', 'read-archive-tree',
                'read-site-meetings', 'create-site-meetings', 'update-site-meetings', 'print-site-meetings',
                'create-notices', 'read-delivery-notes', 'create-delivery-notes', 'print-delivery-notes', 'read-gate-passes', 'create-gate-passes', 'print-gate-passes',
                'read-transport-dashboard', 'read-transport-vehicles', 'read-transport-records', 'create-transport-records', 'update-transport-records', 'read-transport-locations',
                'read-material-requests', 'create-material-requests', 'read-checkins', 'read-checkouts', 'read-transfers',
                'create-contacts', 'update-contacts', 'print-contacts', 'read-reports',
                'assign-task-manager', 'assign-multiple-task-manager', 'create-task-manager', 'change-priority-task-manager', 'attach-task-manager',
            ],

            'Receptionist' => [
                // Visitor Logs: the front desk registers visitors and incoming items, checks visitors out and hands items over (no sensitive details, no corrections, no settings)
                'read-visitor-logs', 'view-all-visits', 'view-active-visitors', 'create-visitor-logs', 'update-visitor-logs', 'checkout-visitor-logs', 'read-visitors', 'create-visitors', 'update-visitors', 'view-visitor-history',
                'read-incoming-items', 'create-incoming-items', 'update-incoming-items', 'update-incoming-item-status', 'handover-incoming-items', 'attach-incoming-items', 'view-incoming-item-history', 'read-visitor-reports', 'print-visitor-reports',
                // the front desk: visitors, deliveries, phone and notices
                'create-contacts', 'update-contacts', 'print-contacts',
                'read-delivery-notes', 'create-delivery-notes', 'print-delivery-notes', 'read-gate-passes', 'create-gate-passes', 'print-gate-passes',
                'read-checkins', 'read-transfers', 'read-staffs', 'read-travels', 'read-site-meetings', 'print-site-meetings',
                'read-archive', 'preview-archive-files', 'read-compliance-records', 'read-assets',
                'read-digital-attendance', 'read-all-digital-attendance',
                'create-task-manager', 'assign-task-manager', 'attach-task-manager', 'read-transport-records',
            ],
        ];
    }

    /** Creates / completes the roles. Returns a short report per role. @return array<string,array{created:bool,added:int,missing:string[]}> */
    public static function apply(int $accountId = 1): array
    {
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->flip();
        $report = [];
        foreach (self::roles() as $name => $permissions) {
            $role = Role::withoutGlobalScopes()->where('name', $name)->where('guard_name', 'web')->first();
            $created = false;
            if (! $role) {
                $role = Role::create(['name' => $name, 'guard_name' => 'web', 'account_id' => $accountId]);
                $created = true;
            }
            $wanted = array_values(array_unique(array_merge(self::EVERYONE, $permissions)));
            $found = array_values(array_filter($wanted, fn ($p) => isset($existing[$p])));
            $before = $role->permissions()->count();
            $role->givePermissionTo($found); // adds only
            $role->forceFill(['all_warehouses' => true])->save(); // "All warehouses" for these roles
            $report[$name] = ['created' => $created, 'added' => $role->permissions()->count() - $before, 'missing' => array_values(array_diff($wanted, $found))];
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $report;
    }
}
