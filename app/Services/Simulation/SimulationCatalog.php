<?php

namespace App\Services\Simulation;

/**
 * What the Import Centre may take from production, and how records of each kind hang together. A table that is not named here (as a module, a child or a
 * reference table) can never be read, and nothing here is typed by the browser. Relations that the database itself declares (foreign keys) are found
 * automatically; the ones this file lists are the tables that declare none, or that need a decision (for example "drop this link").
 */
final class SimulationCatalog
{
    /** @return array<string,array> the modules a person can import records of */
    public static function modules(): array
    {
        return [
            'contacts' => ['label' => 'Contacts and subcontractors', 'table' => 'contacts', 'conn' => 'main', 'title' => 'name', 'sub' => ['category', 'phone'], 'search' => ['name', 'phone', 'email', 'category'], 'filter' => ['column' => 'category', 'label' => 'Category'],
                'class' => \App\Models\Contact::class, 'personal' => ['phone', 'email', 'details']],
            'projects' => ['label' => 'Projects and project budgets', 'table' => 'warehouses', 'conn' => 'main', 'title' => 'name', 'sub' => ['code', 'status', 'site_budget'], 'search' => ['name', 'code'], 'filter' => ['column' => 'status', 'label' => 'Status'],
                'class' => \App\Models\Warehouse::class, 'personal' => ['phone', 'email']],
            'subcontracts' => ['label' => 'Subcontract agreements and contract items', 'table' => 'subcontract_agreements', 'conn' => 'main', 'title' => 'title', 'sub' => ['reference', 'currency', 'status', 'original_amount'], 'search' => ['reference', 'title', 'site'],
                'filter' => ['column' => 'status', 'label' => 'Status'], 'class' => \App\Models\SubcontractAgreement::class, 'personal' => []],
            'vouchers' => ['label' => 'Payment vouchers and line items', 'table' => 'payment_vouchers', 'conn' => 'main', 'title' => 'payee_name', 'sub' => ['pv_number', 'status', 'total_amount'], 'search' => ['pv_number', 'payee_name'],
                'filter' => ['column' => 'status', 'label' => 'Status'], 'class' => \App\Models\PaymentVoucher::class, 'personal' => ['recipient_name']],
            'certificates' => ['label' => 'Payment certificates and certificate items', 'table' => 'project_certificates', 'conn' => 'main', 'title' => 'reference', 'sub' => ['certificate_type', 'status', 'net_payment_amount', 'currency'], 'search' => ['reference', 'certificate_number', 'description'],
                'filter' => ['column' => 'status', 'label' => 'Status'], 'class' => \App\Models\ProjectCertificate::class, 'personal' => []],
            'labour' => ['label' => 'Labour records and labour payments', 'table' => 'labour_lists', 'conn' => 'main', 'title' => 'reference', 'sub' => ['status', 'total_amount', 'period_end'], 'search' => ['reference', 'description'],
                'filter' => ['column' => 'status', 'label' => 'Status'], 'class' => \App\Models\LabourList::class, 'personal' => []],
            'transport' => ['label' => 'Transport records', 'table' => 'transport_records', 'conn' => 'main', 'title' => 'trip_number', 'sub' => ['trip_date', 'amount', 'payment_status'], 'search' => ['trip_number', 'purpose', 'departure_label', 'destination_label'],
                'filter' => ['column' => 'payment_status', 'label' => 'Payment'], 'class' => \App\Models\TransportRecord::class, 'personal' => []],
            'staff' => ['label' => 'Staff and salaries', 'table' => 'staffs', 'conn' => 'main', 'title' => 'name', 'sub' => ['staff_number', 'position', 'status'], 'search' => ['name', 'staff_number', 'position'], 'filter' => ['column' => 'status', 'label' => 'Status'],
                'class' => \App\Models\Staff::class, 'personal' => ['mobile_no', 'personal_email', 'address', 'date_of_birth', 'father_name', 'father_contact_no', 'bank_name', 'account_holder_name', 'account_no', 'tin_number', 'nssf_number', 'photo']],
            'items' => ['label' => 'Items and materials', 'table' => 'items', 'conn' => 'main', 'title' => 'name', 'sub' => ['code', 'sku', 'price'], 'search' => ['name', 'code', 'sku'], 'filter' => null, 'class' => \App\Models\Item::class, 'personal' => ['photo']],
            'expenses' => ['label' => 'Accounts and expenses (Accounts Tracking)', 'table' => 'acct_transactions', 'conn' => 'accounts', 'title' => 'reference', 'sub' => ['kind', 'status', 'amount', 'currency'], 'search' => ['reference', 'description'],
                'filter' => ['column' => 'status', 'label' => 'Status'], 'class' => null, 'personal' => []],
        ];
    }

    /** records that belong to a record: parent table => [[child table, column that points to the parent, label, imported by default]] */
    public static function children(): array
    {
        return [
            'subcontract_agreements' => [['subcontract_work_groups', 'agreement_id', 'Work schedule groups', true], ['subcontract_work_items', 'agreement_id', 'Contract items (work schedule)', true], ['subcontract_historical_payments', 'agreement_id', 'Opening balance and payment history', true],
                ['subcontract_variations', 'agreement_id', 'Variations', false], ['subcontract_payment_requests', 'agreement_id', 'Payment requests', false]],
            'subcontract_payment_requests' => [['subcontract_request_items', 'request_id', 'Request lines', true], ['subcontract_payments', 'request_id', 'Voucher payments', false]],
            'subcontract_payments' => [['subcontract_payment_allocations', 'payment_id', 'Payment allocations', true]],
            'payment_vouchers' => [['payment_voucher_items', 'payment_voucher_id', 'Voucher line items', true]],
            'project_certificates' => [['project_certificate_lines', 'certificate_id', 'Certificate items', true]],
            'labour_lists' => [['labour_list_items', 'labour_list_id', 'Labour list people and amounts', true]],
        ];
    }

    /** tables whose rows were copied when the simulation was installed: a link to one of them keeps its id when the row is there, otherwise the row is brought in */
    public static function reference(): array
    {
        return array_merge(SimulationInstaller::REFERENCE_TABLES, SimulationInstaller::REFERENCE_ACCOUNTS_TABLES);
    }

    /** tables that live in the Accounts Tracking schema */
    public static function accountsTables(): array
    {
        return ['acct_accounts', 'acct_alerts', 'acct_approval_rules', 'acct_approvals', 'acct_attachments', 'acct_audit', 'acct_categories', 'acct_currencies', 'acct_exchange_rates', 'acct_recon_items', 'acct_reconciliations', 'acct_transactions', 'acct_transfers'];
    }

    /**
     * Links the database does not declare, or that need a decision. column => [table it points to, or null = do not carry the link over].
     * A link to people (users) is never carried over (it becomes the person who imported, or nothing); a link not named here and not guessable becomes nothing.
     */
    public static function links(): array
    {
        return [
            'site_meetings' => ['chairperson_id' => '@user', 'recorder_id' => '@user'],
            'subcontract_agreements' => ['contact_id' => 'contacts', 'warehouse_id' => 'warehouses', 'import_sheet_id' => null, 'completed_by_user_id' => '@user', 'completion_verified_by_user_id' => '@user'],
            'subcontract_work_groups' => ['agreement_id' => 'subcontract_agreements', 'warehouse_id' => 'warehouses'],
            'subcontract_work_items' => ['agreement_id' => 'subcontract_agreements', 'group_id' => 'subcontract_work_groups', 'variation_id' => 'subcontract_variations', 'beneficiary_contact_id' => 'contacts'],
            'subcontract_historical_payments' => ['agreement_id' => 'subcontract_agreements', 'work_item_id' => 'subcontract_work_items', 'import_sheet_id' => null],
            'subcontract_variations' => ['agreement_id' => 'subcontract_agreements'],
            'subcontract_payment_requests' => ['agreement_id' => 'subcontract_agreements', 'contact_id' => 'contacts', 'warehouse_id' => 'warehouses', 'claim_line_id' => null],
            'subcontract_request_items' => ['request_id' => 'subcontract_payment_requests', 'work_item_id' => 'subcontract_work_items'],
            'subcontract_payments' => ['request_id' => 'subcontract_payment_requests', 'agreement_id' => 'subcontract_agreements', 'payment_voucher_id' => 'payment_vouchers', 'claim_id' => null, 'claim_line_id' => null],
            'subcontract_payment_allocations' => ['payment_id' => 'subcontract_payments', 'work_item_id' => 'subcontract_work_items'],
            'payment_vouchers' => ['subcontract_payment_id' => 'subcontract_payments', 'subcontract_claim_id' => null],
            'acct_transactions' => ['account_id' => 'acct_accounts', 'category_id' => 'acct_categories', 'contact_id' => 'contacts', 'staff_id' => 'staffs', 'warehouse_id' => 'warehouses', 'fund_allocation_id' => 'fund_allocations', 'fund_item_line_id' => 'fund_allocation_item_lines',
                'source_id' => null, 'transfer_id' => null, 'reverses_id' => null, 'reversed_by_id' => null, 'rate_id' => null, 'reconciliation_id' => null],
            'acct_accounts' => ['fund_account_id' => 'fund_accounts', 'warehouse_id' => 'warehouses'],
        ];
    }

    /** models whose documents (attachments) can be copied: table => model class */
    public static function attachmentClasses(): array
    {
        return ['checkins' => \App\Models\Checkin::class, 'compliance_records' => \App\Models\ComplianceRecord::class, 'daily_site_reports' => \App\Models\DailySiteReport::class, 'material_requests' => \App\Models\MaterialRequest::class,
            'staffs' => \App\Models\Staff::class, 'warehouses' => \App\Models\Warehouse::class];
    }

    /**
     * Every kind of business record the FULL import brings in (people, secrets, messages, biometrics, logs and settings are never in this list).
     * Their parents are pulled in automatically; the order below does not matter (the importer orders them by their links).
     */
    public static function fullImportTables(): array
    {
        return [
            // places, people and things
            'warehouses', 'contacts', 'staffs', 'items', 'stocks', 'stock_trails', 'assets', 'asset_movements', 'asset_placements', 'asset_photos', 'transport_locations',
            // stock movements
            'checkins', 'checkin_items', 'checkouts', 'checkout_items', 'transfers', 'transfer_items', 'adjustments', 'adjustment_items', 'super_adjustments', 'super_adjustment_items',
            'material_requests', 'material_request_items', 'material_request_status_logs', 'material_reallocations', 'material_reallocation_items', 'material_reallocation_logs', 'delivery_notes', 'gate_passes',
            // projects and site work
            'daily_site_reports', 'daily_site_report_logs', 'site_meetings', 'site_meeting_attendees', 'site_meeting_rows', 'work_activities', 'labour_work_items', 'boq_material_compliances',
            'project_certificate_contracts', 'project_certificates', 'project_certificate_lines', 'labour_lists', 'labour_list_items',
            // money
            'payment_vouchers', 'payment_voucher_items', 'payment_voucher_particulars', 'fund_accounts', 'fund_allocation_balances', 'supplier_ledger_entries', 'transport_records', 'transport_loading_places',
            'transport_loading_attendances', 'salary_components', 'salary_advances', 'salary_loans', 'salary_opening_balances',
            'subcontract_agreements', 'subcontract_work_groups', 'subcontract_work_items', 'subcontract_historical_payments', 'subcontract_variations', 'subcontract_payment_requests', 'subcontract_request_items',
            'subcontract_payments', 'subcontract_payment_allocations', 'subcontract_reconciliations', 'subcontract_project_mappings',
            // compliance and archive
            'compliance_records', 'compliance_history', 'compliance_document_versions', 'archive_records', 'archive_files', 'attachments',
            // Accounts Tracking (its own schema)
            'acct_accounts', 'acct_transactions', 'acct_transfers', 'acct_exchange_rates', 'acct_reconciliations', 'acct_recon_items',
        ];
    }

    /** a column that points at a row of a table named by another column (a "type"): table => [id column, type column, type value => table] */
    public static function polymorphic(): array
    {
        $att = ['App\\Models\\Checkin' => 'checkins', 'App\\Models\\ComplianceRecord' => 'compliance_records', 'App\\Models\\DailySiteReport' => 'daily_site_reports', 'App\\Models\\MaterialRequest' => 'material_requests',
            'App\\Models\\Staff' => 'staffs', 'App\\Models\\Warehouse' => 'warehouses', 'App\\Models\\Task' => 'tasks', 'App\\Models\\SubcontractAgreement' => 'subcontract_agreements', 'App\\Models\\PaymentVoucher' => 'payment_vouchers',
            'App\\Models\\SubcontractPaymentRequest' => 'subcontract_payment_requests', 'App\\Models\\SubcontractVariation' => 'subcontract_variations', 'App\\Models\\Transfer' => 'transfers', 'App\\Models\\Contact' => 'contacts'];

        return [
            'delivery_notes' => ['id' => 'source_id', 'type' => 'source_type', 'map' => ['transfer' => 'transfers', 'checkin' => 'checkins']],
            'gate_passes' => ['id' => 'source_id', 'type' => 'source_type', 'map' => ['transfer' => 'transfers', 'checkin' => 'checkins']],
            'stock_trails' => ['id' => 'subject_id', 'type' => 'subject_type', 'map' => ['App\\Models\\AdjustmentItem' => 'adjustment_items', 'App\\Models\\CheckinItem' => 'checkin_items', 'App\\Models\\CheckoutItem' => 'checkout_items', 'App\\Models\\TransferItem' => 'transfer_items']],
            'attachments' => ['id' => 'model_id', 'type' => 'model_type', 'map' => $att],
        ];
    }

    /** the modules as the page needs them (label, filter) */
    public static function forPage(): array
    {
        return collect(self::modules())->map(fn ($m, $k) => ['key' => $k, 'label' => __($m['label']), 'filter' => $m['filter'] ? ['column' => $m['filter']['column'], 'label' => __($m['filter']['label'])] : null])->values()->all();
    }
}
