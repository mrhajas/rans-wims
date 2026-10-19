<?php

namespace App\Support\Qr;

use App\Models\Asset;
use App\Models\User;

/**
 * Which records can carry a WIMS QR code, and where a scan of that code goes.
 *
 * One entry per kind of record: the model, the page the scan opens (route + parameter name), the ability that page needs
 * and the attributes that name the record on a label. Nothing is added to the record tables: a code is a row of `qr_codes`
 * made the first time somebody asks for it. A new kind of record becomes scannable by adding one entry here.
 *
 * 'param' is the route parameter that holds the record; 'also' lists extra route parameters the target needs and how to fill them.
 */
class QrRegistry
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $m = fn (string $n) => 'App\\Models\\' . $n;

        return [
            // stock movements
            'transfer' => ['label' => 'Transfer', 'model' => $m('Transfer'), 'route' => 'transfers.show', 'param' => 'transfer', 'can' => ['read-transfers']],
            'multi_transfer' => ['label' => 'Multi Transfer', 'model' => $m('MultiTransfer'), 'route' => 'multi-transfers.show', 'param' => 'multi_transfer', 'can' => ['read-multi-transfers']],
            'checkin' => ['label' => 'Checkin', 'model' => $m('Checkin'), 'route' => 'checkins.show', 'param' => 'checkin', 'can' => ['read-checkins']],
            'checkout' => ['label' => 'Checkout', 'model' => $m('Checkout'), 'route' => 'checkouts.show', 'param' => 'checkout', 'can' => ['read-checkouts']],
            'adjustment' => ['label' => 'Adjustment', 'model' => $m('Adjustment'), 'route' => 'adjustments.show', 'param' => 'adjustment', 'can' => ['read-adjustments']],
            'super_adjustment' => ['label' => 'Super Adjustment', 'model' => $m('SuperAdjustment'), 'route' => 'super-adjustments.show', 'param' => 'super_adjustment', 'can' => ['read-super-adjustments']],
            'issue' => ['label' => 'Issue & Return', 'model' => $m('IssueTransaction'), 'route' => 'issue-materials.show', 'param' => 'issueMaterial', 'can' => ['read-issue-returns']],
            'material_request' => ['label' => 'Material Request', 'model' => $m('MaterialRequest'), 'route' => 'material-requests.show', 'param' => 'material_request', 'can' => ['read-material-requests', 'view-all-material-requests']],
            'material_reallocation' => ['label' => 'Material Reallocation', 'model' => $m('MaterialReallocation'), 'route' => 'material-reallocations.show', 'param' => 'material_reallocation', 'can' => ['read-material-reallocations']],
            'delivery_note' => ['label' => 'Delivery Note', 'model' => $m('DeliveryNote'), 'route' => 'delivery-notes.show', 'param' => 'delivery_note', 'can' => ['read-delivery-notes']],
            'gate_pass' => ['label' => 'Gate Pass', 'model' => $m('GatePass'), 'route' => 'gate-passes.show', 'param' => 'gate_pass', 'can' => ['read-gate-passes']],
            'external_stock' => ['label' => 'External Stock', 'model' => $m('ExternalStockMovement'), 'route' => 'external-stock.show', 'param' => 'movement', 'can' => ['read-external-stock'], 'scope' => 'visibleTo'],
            'travel' => ['label' => 'Travel', 'model' => $m('Travel'), 'route' => 'travels.show', 'param' => 'travel', 'can' => ['read-travels'], 'scope' => 'visibleTo'],
            'travel_payment' => ['label' => 'Travel Agent Payment', 'model' => $m('TravelAgentPayment'), 'route' => 'travels.agents.payments.show', 'param' => 'payment', 'can' => ['read-travel-agents']],
            'subcontract_agreement' => ['label' => 'Subcontract Agreement', 'model' => $m('SubcontractAgreement'), 'route' => 'subcontracts.agreements.show', 'param' => 'agreement', 'can' => ['read-subcontract-agreements'], 'scope' => 'visibleTo'],
            'subcontract_request' => ['label' => 'Subcontract Payment Request', 'model' => $m('SubcontractPaymentRequest'), 'route' => 'subcontracts.requests.show', 'param' => 'payreq', 'can' => ['read-subcontract-requests']],
            'subcontract_variation' => ['label' => 'Subcontract Variation', 'model' => $m('SubcontractVariation'), 'route' => 'subcontracts.variations.show', 'param' => 'variation', 'can' => ['read-subcontract-variations']],
            'acct_transaction' => ['label' => 'Account Transaction', 'model' => $m('Accounts\\AcctTransaction'), 'route' => 'acct.transactions.show', 'param' => 'id', 'can' => ['read-acct-transactions']],
            'acct_transfer' => ['label' => 'Account Transfer', 'model' => $m('Accounts\\AcctTransfer'), 'route' => 'acct.transfers.show', 'param' => 'id', 'can' => ['read-acct-transfers']],
            'subcontract_reconciliation' => ['label' => 'Subcontract Reconciliation', 'model' => $m('SubcontractAgreement'), 'route' => 'subcontracts.reconciliation.show', 'param' => 'agreement', 'can' => ['reconcile-subcontracts', 'approve-reconciliation-subcontracts'], 'scope' => 'visibleTo'],
            'acct_account' => ['label' => 'Tracked Account', 'model' => $m('Accounts\\AcctAccount'), 'route' => 'acct.accounts.show', 'param' => 'id', 'can' => ['read-acct-accounts', 'view-all-acct-accounts']],
            'acct_reconciliation' => ['label' => 'Account Reconciliation', 'model' => $m('Accounts\\AcctReconciliation'), 'route' => 'acct.reconciliations.show', 'param' => 'id', 'can' => ['perform-acct-reconciliations', 'approve-acct-reconciliations', 'read-acct-accounts']],
            'acct_fund' => ['label' => 'Funds Allocation (Accounts)', 'model' => $m('FundAllocation'), 'route' => 'acct.funds.show', 'param' => 'id', 'can' => ['read-acct-transactions']],
            'salary_period' => ['label' => 'Salary Period', 'model' => $m('SalaryPeriod'), 'route' => 'salary.periods.show', 'param' => 'salary_period', 'can' => ['read-salary-periods']],
            'salary_advance' => ['label' => 'Salary Advance', 'model' => $m('SalaryAdvance'), 'route' => 'salary.advances.show', 'param' => 'salary_advance', 'can' => ['read-salary-advances']],
            'salary_loan' => ['label' => 'Salary Loan', 'model' => $m('SalaryLoan'), 'route' => 'salary.loans.show', 'param' => 'salary_loan', 'can' => ['read-salary-loans']],
            'payment_batch' => ['label' => 'Payment Voucher Batch', 'model' => $m('PaymentVoucherBatch'), 'route' => 'payment-vouchers.batches.show', 'param' => 'payment_voucher_batch', 'can' => ['read-payment-vouchers']],
            'item' => ['label' => 'Inventory Item', 'model' => $m('Item'), 'route' => 'items.show', 'param' => 'item', 'can' => ['read-items']],

            // projects, contracts, certificates
            'project' => ['label' => 'Project / Warehouse', 'model' => $m('Warehouse'), 'route' => 'warehouses.edit', 'param' => 'warehouse', 'can' => ['update-warehouses']],
            'project_contract' => ['label' => 'Project Contract', 'model' => $m('Warehouse'), 'route' => 'project-certificates.contract.edit', 'param' => 'warehouse', 'can' => ['view-project-certificates']],
            'certificate' => ['label' => 'Project Certificate', 'model' => $m('ProjectCertificate'), 'route' => 'project-certificates.show', 'param' => 'certificate', 'can' => ['view-project-certificates']],
            'compliance' => ['label' => 'Compliance Record', 'model' => $m('ComplianceRecord'), 'route' => 'compliance-records.show', 'param' => 'compliance_record', 'can' => ['read-compliance-records']],
            'work_package' => ['label' => 'Work Package', 'model' => $m('WorkPackage'), 'route' => 'work-packages.show', 'param' => 'work_package', 'can' => ['read-work-packages']],
            'fund_allocation' => ['label' => 'Funds Allocation', 'model' => $m('FundAllocation'), 'route' => 'fund-allocations.show', 'param' => 'fund_allocation', 'can' => ['read-fund-allocations']],

            // suppliers and payments
            'supplier' => ['label' => 'Supplier', 'model' => $m('Contact'), 'route' => 'supplier-records.suppliers.show', 'param' => 'supplier', 'can' => ['read-supplier-records'], 'where' => ['category' => 'Suppliers']],
            'purchase_request' => ['label' => 'Purchase Request', 'model' => $m('PurchaseRequest'), 'route' => 'supplier-records.purchase-requests.show', 'param' => 'purchase_request', 'can' => ['read-purchase-requests']],
            'purchase_order' => ['label' => 'Purchase Order', 'model' => $m('PurchaseOrder'), 'route' => 'supplier-records.purchase-orders.show', 'param' => 'purchase_order', 'can' => ['read-purchase-orders']],
            'quotation' => ['label' => 'Quotation', 'model' => $m('Quotation'), 'route' => 'supplier-records.quotations.show', 'param' => 'quotation', 'can' => ['read-quotations']],
            'goods_receipt' => ['label' => 'Goods Receipt', 'model' => $m('GoodsReceipt'), 'route' => 'supplier-records.goods-receipts.show', 'param' => 'goods_receipt', 'can' => ['read-goods-receipts']],
            'supplier_invoice' => ['label' => 'Supplier Invoice', 'model' => $m('SupplierInvoice'), 'route' => 'supplier-records.supplier-invoices.show', 'param' => 'supplier_invoice', 'can' => ['read-supplier-invoices']],
            'payment_request' => ['label' => 'Payment Request', 'model' => $m('PaymentRequest'), 'route' => 'supplier-records.payment-requests.show', 'param' => 'payment_request', 'can' => ['read-payment-requests']],
            'supplier_payment' => ['label' => 'Supplier Payment', 'model' => $m('Payment'), 'route' => 'supplier-records.payments.show', 'param' => 'payment', 'can' => ['read-payments']],
            'payment_voucher' => ['label' => 'Payment Voucher', 'model' => $m('PaymentVoucher'), 'route' => 'payment-vouchers.show', 'param' => 'payment_voucher', 'can' => ['read-payment-vouchers']],
            'salary_voucher' => ['label' => 'Salary Voucher', 'model' => $m('SalaryVoucher'), 'route' => 'salary.vouchers.show', 'param' => 'salary_voucher', 'can' => ['read-salary-vouchers']],

            // people
            'staff' => ['label' => 'Staff Member', 'model' => $m('Staff'), 'route' => 'staffs.show', 'param' => 'staff', 'can' => ['read-staffs']],
            'workforce' => ['label' => 'Workforce Agreement', 'model' => $m('WorkforceAgreement'), 'route' => 'workforce-agreements.show', 'param' => 'workforce_agreement', 'can' => ['read-workforce-agreements']],
            'labour' => ['label' => 'Labour List', 'model' => $m('LabourList'), 'route' => 'labour.show', 'param' => 'labour', 'can' => ['read-labour']],
            'subcontractor' => ['label' => 'Subcontractor', 'model' => $m('Contact'), 'route' => 'subcontracts.register.show', 'param' => 'contact', 'can' => ['read-subcontracts'], 'where' => ['category' => 'SubContractors']],
            'loading_worker' => ['label' => 'Loading and Unloading Worker', 'model' => $m('Contact'), 'route' => 'transport-loading.show', 'param' => 'contact', 'can' => ['read-transport-loading']],
            'contact' => ['label' => 'Contact', 'model' => $m('Contact'), 'route' => 'contacts.edit', 'param' => 'contact', 'can' => ['read-contacts']],

            // assets, equipment, transport
            'asset' => ['label' => 'Asset', 'model' => Asset::class, 'route' => 'assets.show', 'param' => 'asset', 'can' => ['read-assets']],
            'vehicle' => ['label' => 'Vehicle / Equipment', 'model' => Asset::class, 'route' => 'transport-vehicles.show', 'param' => 'asset', 'can' => ['read-transport-vehicles']],
            'transport_record' => ['label' => 'Transport Record', 'model' => $m('TransportRecord'), 'route' => 'transport-records.show', 'param' => 'transport_record', 'can' => ['read-transport-records']],

            // documents and site records
            'archive' => ['label' => 'Archive Document', 'model' => $m('ArchiveRecord'), 'route' => 'archive.records.show', 'param' => 'archive_record', 'can' => ['read-archive'], 'scope' => 'visibleTo'],
            'site_diary' => ['label' => 'Site Diary', 'model' => $m('SiteDiary'), 'route' => 'site-diary.show', 'param' => 'diary', 'can' => ['read-site-diary', 'view-all-site-diaries']],
            'site_meeting' => ['label' => 'Site Meeting', 'model' => $m('SiteMeeting'), 'route' => 'site-meetings.show', 'param' => 'site_meeting', 'can' => ['read-site-meetings', 'view-all-site-meetings']],
            'site_report' => ['label' => 'Daily Site Report', 'model' => $m('DailySiteReport'), 'route' => 'daily-site-reports.show', 'param' => 'daily_site_report', 'can' => ['read-daily-site-reports']],
            'notice' => ['label' => 'Notice', 'model' => $m('Notice'), 'route' => 'notices.show', 'param' => 'notice', 'can' => ['read-notices']],
            'case' => ['label' => 'Case', 'model' => $m('CaseTicket'), 'route' => 'case-tracking.show', 'param' => 'case_ticket', 'can' => ['read-case-tracking', 'read-all-case-tracking']],
        ];
    }

    /** The module a kind of record belongs to, for QR Activation. */
    public const GROUPS = [
        'Stock and transfers' => ['transfer', 'multi_transfer', 'checkin', 'checkout', 'adjustment', 'super_adjustment', 'issue', 'material_request', 'material_reallocation', 'delivery_note', 'gate_pass', 'external_stock', 'subcontract_agreement', 'subcontract_variation', 'subcontract_request', 'subcontract_reconciliation', 'item'],
        'Projects and contracts' => ['project', 'project_contract', 'certificate', 'compliance', 'work_package', 'fund_allocation'],
        'Suppliers and payments' => ['supplier', 'purchase_request', 'purchase_order', 'quotation', 'goods_receipt', 'supplier_invoice', 'payment_request', 'supplier_payment', 'payment_voucher', 'payment_batch', 'salary_voucher'],
        'Accounts and salary' => ['acct_account', 'acct_transaction', 'acct_transfer', 'acct_reconciliation', 'acct_fund', 'salary_period', 'salary_advance', 'salary_loan'],
        'People' => ['staff', 'workforce', 'labour', 'contact', 'subcontractor', 'loading_worker'],
        'Travels' => ['travel', 'travel_payment'],
        'Assets and transport' => ['asset', 'vehicle', 'transport_record'],
        'Documents and site records' => ['archive', 'site_diary', 'site_meeting', 'site_report', 'case', 'notice'],
    ];

    /** The icon that stands for each kind on the QR Activation page. */
    public const ICONS = [
        'transfer' => 'truck', 'multi_transfer' => 'collection', 'checkin' => 'in', 'checkout' => 'out', 'adjustment' => 'adjustments', 'super_adjustment' => 'toggle', 'issue' => 'refresh',
        'material_request' => 'doc-text', 'material_reallocation' => 'forward', 'delivery_note' => 'doc', 'gate_pass' => 'lock-open', 'external_stock' => 'truck', 'travel' => 'plane', 'travel_payment' => 'cash', 'subcontract_agreement' => 'doc-text', 'subcontract_variation' => 'adjustments', 'subcontract_reconciliation' => 'tick', 'acct_account' => 'cash', 'acct_reconciliation' => 'check-double', 'acct_fund' => 'collection', 'salary_period' => 'calendar', 'salary_advance' => 'cash', 'salary_loan' => 'cash', 'payment_batch' => 'collection', 'subcontractor' => 'users', 'loading_worker' => 'user', 'notice' => 'bell', 'subcontract_request' => 'send', 'acct_transaction' => 'cash', 'acct_transfer' => 'refresh', 'item' => 'chip',
        'project' => 'building', 'project_contract' => 'doc-text', 'certificate' => 'tick', 'compliance' => 'check-double', 'work_package' => 'list', 'fund_allocation' => 'cash',
        'supplier' => 'group', 'purchase_request' => 'cart', 'purchase_order' => 'bag', 'quotation' => 'calculator', 'goods_receipt' => 'in', 'supplier_invoice' => 'doc', 'payment_request' => 'send',
        'supplier_payment' => 'cash', 'payment_voucher' => 'doc-text', 'salary_voucher' => 'cash',
        'staff' => 'user', 'workforce' => 'users', 'labour' => 'group', 'contact' => 'at',
        'asset' => 'chip', 'vehicle' => 'car', 'transport_record' => 'road',
        'archive' => 'archive-box', 'site_diary' => 'camera', 'site_meeting' => 'meeting-calendar', 'site_report' => 'doc', 'case' => 'exclamation',
    ];

    public static function group(string $type): string
    {
        foreach (self::GROUPS as $name => $types) {
            if (in_array($type, $types, true)) {
                return $name;
            }
        }

        return 'Other';
    }

    /** A record of a kind by id, trashed or not, ignoring scopes (not every model, e.g. Accounts Tracking, uses soft deletes). */
    public static function findAny(string $model, $id)
    {
        $q = $model::withoutGlobalScopes();
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
            $q->withTrashed();
        }

        return $q->find($id);
    }

    /** Is this kind's QR switched on (QR Activation)? */
    public static function active(string $type): bool
    {
        return ! in_array($type, \App\Services\Qr\QrSettings::get()['disabled_kinds'], true);
    }

    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /** The entry whose page is the named route (the details view a person is looking at), or null. */
    public static function forRoute(?string $routeName): ?array
    {
        if (! $routeName) {
            return null;
        }
        foreach (self::all() as $type => $e) {
            if ($e['route'] === $routeName) {
                return ['type' => $type] + $e;
            }
        }

        return null;
    }

    /** May this person open this kind of record's page at all? */
    public static function canOpen(User $user, array $entry): bool
    {
        foreach ($entry['can'] as $ability) {
            if ($user->can($ability)) {
                return true;
            }
        }

        return false;
    }

    /** The record as THIS person sees it (their own global scopes and the kind's own visibility rule), or null when it is not theirs to see. */
    public static function findFor(User $user, string $type, int $id)
    {
        $e = self::get($type);
        if (! $e || ! class_exists($e['model'])) {
            return null;
        }
        $q = $e['model']::query();
        if (! empty($e['where'])) {
            $q->where($e['where']);
        }
        if (! empty($e['scope']) && method_exists($e['model'], 'scope' . ucfirst($e['scope']))) {
            $q->{$e['scope']}($user);
        }

        return $q->find($id);
    }

    /** Which of these ids of one kind may THIS person open (their own scopes and the kind's visibility rule)? One query per kind, for lists. */
    public static function visibleIds(User $user, string $type, array $ids): array
    {
        $e = self::get($type);
        if (! $e || ! $ids || ! class_exists($e['model']) || ! self::active($type) || ! self::canOpen($user, $e)) {
            return [];
        }
        $q = $e['model']::query();
        if (! empty($e['where'])) {
            $q->where($e['where']);
        }
        if (! empty($e['scope']) && method_exists($e['model'], 'scope' . ucfirst($e['scope']))) {
            $q->{$e['scope']}($user);
        }

        return $q->whereIn($q->getModel()->getQualifiedKeyName(), $ids)->pluck($q->getModel()->getQualifiedKeyName())->map(fn ($v) => (int) $v)->all();
    }

    /** The words that name a record on a label: its reference and a short description. */
    public static function describe($model): array
    {
        $first = function (array $cols) use ($model) {
            foreach ($cols as $c) {
                $v = $model->getAttribute($c);
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }

            return null;
        };

        $reference = $first(['reference', 'pv_number', 'asset_number', 'staff_number', 'compliance_number', 'certificate_number', 'trip_number', 'voucher_number', 'report_number', 'case_number', 'code', 'number']);
        $title = $first(['name', 'title', 'subject', 'display_title', 'details']);
        if ($title && $reference && mb_strtolower($title) === mb_strtolower($reference)) {
            $title = null;
        }

        return ['reference' => $reference ?: ($title ?: '-'), 'title' => $reference ? \Illuminate\Support\Str::limit((string) $title, 60) : null];
    }
}
