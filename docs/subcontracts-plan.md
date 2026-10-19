# Subcontractor Management: implementation plan (draft, 2026-10-17)

Status: codebase review done. Workbook analysis NOT done: the file `SUB CONTRACTS  15th OCT, 2026.xlsx` sits in ~/Downloads, which macOS blocks for this tool ("Operation not permitted"), so none of its 62 sheets has been read. Nothing is built yet and nothing in this plan is based on guessed workbook content.

## What exists and will be reused (verified in the code)

* **Contacts** (`contacts`: name, category, email, phone, location, details; Contact::CATEGORIES already has `SubContractors`; 13 contacts hold it locally). Subcontractor master = a Contact in that category. No duplicate identity table. Missing but wanted: business registration / TIN and contact person (propose a small extension to Contacts, or keep them in the agreement record, owner to decide).
* **Projects** = Warehouses (status Private / Government = project; Main Warehouse = store). Agreements link to `warehouse_id`, plus a free "site / building / phase" group on work items.
* **Payment Vouchers** (`payment_vouchers`, items, batches): payee by `payee_contact_id`, status Draft > Submitted > Approved > Authorised, paid when Authorised and `paid_at` set, `total_amount` decimal(15,2) with NO currency column (TZS only), `extra_attributes` JSON, other modules already link by FK (`labour_list_id`, `payment_request_id`). Plan: add one nullable `subcontract_payment_id` (and allocation table) and create the voucher through the existing controller/validation; paid state is always read from the voucher (Authorised + paid_at), never from the subcontract module.
* **Payment flow template**: Workforce Agreements / Labour (agreement, entitlement, payments as live sums) and Project Certificates (contract, addendums, payments) show the house style for agreements, variations and balances computed from immutable rows.
* **Platform conventions**: PermissionRegistry blocks (one per sub-function), SystemFeatureRegistry parent + children, menu.js + Launcher entry ending with an Alerts workflow item (`app/Support/Workflow/Modules/*.php`), AppNotification alert module, `log_activity`, HasAttachments, DomPDF/FastExcel report layout (`partials.print.*`, signatories report print), QR registry, ReferenceLock numbering, WIMS date picker / AcctSelect-style searchable dropdowns.

## Gaps to decide with the owner

1. **Currency**: vouchers have none. USD items need either a conversion at payment time (rate + date stored on the subcontract payment, voucher in TZS) or a currency column on vouchers. Proposed: voucher stays TZS; the subcontract module stores original currency amount, rate, rate date and TZS equivalent. The Accounts Tracking rate register can be the source of rates.
2. **Historical payments**: stored in their own table as identifiable legacy/opening transactions (never fake vouchers), with source sheet/row and import batch.
3. **Deductions** (advance, retention, recovery) as typed transactions; only if the workbook shows them.

## Proposed tables (names provisional until the workbook is read)

`subcontract_agreements`, `subcontract_work_groups`, `subcontract_work_items`, `subcontract_variations`, `subcontract_payment_requests` (+ `_allocations`), `subcontract_payments` (link to voucher, status mirrored), `subcontract_historical_payments`, `subcontract_import_batches` / `_rows` (staging + reconciliation), `subcontract_settings` (units, categories, numbering, override rule). Central `SubcontractLedger` service defines every balance once (original, variations, revised, approved work, requested, approved unpaid, paid, outstanding commitment, outstanding payable).

## Build order

1. Workbook analysis + migration mapping (needs the file).
2. Schema, models, ledger service, tests.
3. Agreements, work schedule, variations.
4. Payment requests and Payment Voucher link.
5. Import staging, historical payments, reconciliation.
6. Dashboard, account statement, 25 reports.
7. Permissions, Feature Control, Launcher, notifications, audit, QR, ARIS.
8. UI refinement, security and regression tests, docs, zip (not deployed).
