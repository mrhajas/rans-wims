# Subcontractor Management (WIMS)

Built 2026-10-17/18. Not deployed. Analysis of the workbook: `docs/subcontracts-workbook-analysis.md`. Plan and gaps: `docs/subcontracts-plan.md`.

## 1. What it is

Subcontractors, their agreements, work schedules (BOQ-like), variations, payment requests, the hand-off to Payment Vouchers, account statements, a staged import and reconciliation of the historical Excel workbook, a dashboard, 25 reports and ARIS answers.

Rules that hold everywhere (all in `App\Services\Subcontracts\SubcontractLedger`, the one place that defines a figure):

* The subcontractor is a **Contact in the SubContractors category** (no second identity table). The project is a **Warehouse**.
* **Original contract value** is stored once and never overwritten. **Revised = original + approved increases - approved reductions.** A draft, submitted or rejected variation changes nothing.
* A request being submitted or approved is **never** money paid. **Paid** means the Payment Voucher is Authorised **and** has a paid date (`SubcontractLedger::voucherIsPaid`), plus *counted* historical (Excel) payments. Cancelled, rejected or deleted vouchers are ignored.
* Figures of different currencies are never added; every total is per currency. Vouchers are TZS only (the voucher table has no currency): a payment in another currency stores the amount, the rate, the rate date and the TZS amount the voucher must carry.
* Approving a request above the revised contract value still open is refused unless a person with `override-subcontract-limits` gives a reason (settings can switch overrides off); every override is in the audit log and alerts the administrators.
* A person cannot review or approve what they created or submitted (a Super Admin can; it is logged).

Definitions shown on the pages and reports: original, variations (net), revised, scheduled (work schedule total), requested pending, approved requests, approved work (= counted historical payments + approved request amounts), in a voucher not paid yet, paid (vouchers + historical), approved unpaid (approved minus paid on those requests), outstanding commitment (revised minus paid), headroom (revised minus approved work).

## 2. Tables (main database, migration `2026_11_10_200000_create_subcontracts`)

`subcontract_agreements`, `subcontract_work_groups`, `subcontract_work_items`, `subcontract_variations`, `subcontract_payment_requests`, `subcontract_request_items`, `subcontract_payments` (request <-> voucher, unique voucher), `subcontract_payment_allocations`, `subcontract_historical_payments` (kind `opening_paid` counted / `dated_evidence` never counted), `subcontract_import_batches`, `subcontract_import_sheets` (staging copy as JSON), `subcontract_reconciliations`; plus `payment_vouchers.subcontract_payment_id`. The same migration seeds the permissions (Super Admin) and the Global Feature Control rows.

## 3. Payment Voucher integration

1. An approved request has **Start Payment Voucher**: amount (at most what is left on the request; one open payment per request) and, for a non-TZS agreement, the rate and its date. This creates a `subcontract_payments` row and opens the **existing** Payment Voucher form pre-filled (payee = the subcontractor, project, total, particulars, remarks).
2. The voucher is created by the existing `PaymentVoucherController::store` with `subcontract_payment_id`; inside its transaction the payment row is locked and linked. The voucher must carry the exact payee and TZS total that were approved (checked on create and on every later edit). The same payment cannot get a second voucher.
3. The voucher goes through its own submit / approve / authorise / record-payment steps and permissions. The subcontract module only reads the result. A voucher that is rejected, cancelled or deleted frees the request for a new voucher.
4. Both sides link to each other (voucher page shows the subcontract request; the request page shows each voucher and its state).

## 4. Historical (Excel) payments

Imported as `subcontract_historical_payments`, never as vouchers and never with a made-up voucher number. The authoritative paid figure is the **PAID of each work item** (it ties to every account total). The dated PAYMENT DATE list, which in this workbook is lower than the paid total on most sheets, is kept as dated evidence and is never added on top (so nothing is counted twice). Each imported account gets a reconciliation row (workbook figures against WIMS, the dated-list total, the gap); a difference stays **open** until a person with `approve-reconciliation-subcontracts` explains it. Differences up to the rounding setting are closed automatically with a note.

Import = upload, stage (nothing written to the live tables), review sheet by sheet (decision, Contact match or new Contact, project, currency, US dollar blocks with their rate), confirm (`confirm-import-subcontracts`), report, and take-back while the agreements have no requests or variations of their own. Staging flags: hidden sheets, sheets not in SUMMARY, sheets listed twice in SUMMARY, other versions of the same account, mixed currencies, items paid above contract, quantity x rate differences, unreadable dates, text lines in payment lists, project accounts with many beneficiaries. See the analysis document for what the workbook contains.

Imported result on the real workbook (demo database): 71 account sheets staged, all 71 import in a test; paid per account equals the item PAID; 66 of 71 accounts match the workbook totals within rounding; 5 stay open for reconciliation (HAFIDH OLD, DERM ELEV, SCAFFOLD, MASOUD JUMA, SAID.HAMAD: the items do not add up to the sheet's own totals). Three accounts differ from the SUMMARY sheet for the same reason.

## 5. Permissions (Roles page, 7 blocks) and Feature Control (9 entries)

Permissions: `read-subcontracts`, `view-financials-subcontracts`, `view-documents-subcontracts`, `view-audit-subcontracts`; agreements: `read|create|update|submit|review|approve|manage|delete-subcontract-agreements`; variations: `read|create|submit|review|approve-subcontract-variations`; requests: `read|create|submit|review|approve-subcontract-requests`, `initiate-subcontract-vouchers`, `override-subcontract-limits`; import: `import-subcontracts`, `confirm-import-subcontracts`, `reconcile-subcontracts`, `approve-reconciliation-subcontracts`; reports: `read-subcontract-reports`, `print-subcontract-reports`; `manage-subcontract-settings`. Only Super Admin holds them after the migration. A person without `view-financials-subcontracts` gets no amount in any page, export or ARIS answer. Agreements are visible to approvers, to the administrator / creator, and to people with access to the project.

Feature Control: `subcontracts` (parent, depends on payment_vouchers), `subcontract_register`, `subcontract_agreements`, `subcontract_variations`, `subcontract_requests`, `subcontract_statements`, `subcontract_reports`, `subcontract_import` (import and reconciliation), `subcontract_settings` (settings and alerts). Disabled features are refused for pages and backend requests; data is kept.

Launcher / menu: group **Subcontractors** (Dashboard, Register, Agreements, New Agreement, Variations, Payment Requests, New Payment Request, Excel Import, Reconciliation, Reports, Settings, Alerts); the Launcher is built from the same menu.

## 6. Alerts, audit, QR, ARIS

Alerts page `/subcontracts/workflow` (who is told, on which channel): agreement waiting / decided, variation waiting / decided, request submitted / returned / decided, voucher started, payment confirmed (when the voucher is paid), overpayment attempt or override, import needs reconciliation, agreement ending. `php artisan subcontracts:scan` (daily 07:30) sends the ending-soon and waited-too-long alerts once a day. Every action is in the activity log (module "Subcontracts"): agreements, work items (amount before and after), variations (with the revised value after), requests (override with reason), payment start and confirmation, imports, reconciliation approvals, settings and every export. QR codes: agreements and payment requests are scannable (QR registry); the agreement detail print carries the agreement QR. ARIS (deterministic, permission checked): contract / paid / approved unpaid / outstanding of a subcontractor, items waiting, unreconciled imported accounts.

## 7. Reports (25), exports

Subcontractor Register, Account Statement, Financial Summary, Consolidated Works Summary (replaces the SUMMARY sheet), Agreements Register, Agreement Details and Breakdown, Work Item Schedule, Work Group Summary, Original vs Revised, Variations, Completed and Terminated, Payment Requests, Pending Approvals, Approved but Unpaid, Voucher History, Actual Payments, Payments by Project, Payments by Work Location, Advances / Retention / Deductions, Currency and Exchange Rates, Outstanding Commitments, Outstanding Payables, Reconciliation, Import Report, Audit and Change History. Screen, Print (PDF), PDF, Excel, CSV in the WIMS report layout; PDF / print are limited to 500 rows (DomPDF memory), Excel / CSV carry everything.

## 8. Tests (demo database only; scripts in the session scratchpad `ver/`)

`sub_core.php` 76 (engine, approvals, maker-checker, variations, requests, voucher hand-off, paid-only-when-paid, partial payments, void vouchers, overrides, legacy counted once, currencies apart, permissions, feature control, statement, scan); `sub_import.php` 22 (the real workbook end to end); `sub_http.php` 59 (upload, every page, all 25 reports, print / PDF / Excel / CSV, exports logged, settings); `sub_aris.php` 3; `sub_conc.sh` 4 (parallel processes: five approvals against a limit, six voucher starts); `qr_print_test.php` 6.

## 9. Not done / known limits

* No retention or recovery is deducted automatically (recorded as terms and advance requests). The workbook has no deduction columns; only remarks (loans, returned goods).
* USD: the voucher is TZS; the rate is typed at payment time (no rate register lookup yet).
* Contacts have no tax number or contact-person field; they are on the agreement terms instead.
* Items with the same description are not de-duplicated; sheets that are older versions of an account must be skipped by the reviewer (they are flagged).
* The import runs in the request (about 11 s for the whole workbook); not queued.
* Access scoping by department is not applied (access is by project and role).

## 11. Soft-coded fields (migration `2026_11_11_100000`)

Settings > Fields lets the people running the module change what is asked, per kind of record (agreement, work item, variation, payment request):

* every OPTIONAL built-in field can be switched off (hidden on forms and ignored on save, existing data stays) or made required; the core fields (subcontractor, title, type, currency, amount, dates that drive approvals) cannot be switched off;
* up to 20 own fields per record kind: short text, long text, number, date, choose-from-a-list, yes/no; each can be required and can be shown as a column in the list and in the report (agreements list and Agreements Register report);
* values are stored in the record's `extra_attributes.custom`; removing a field only hides it, the values stay and come back if it is added again; every change of the settings is logged.
`App\Services\Subcontracts\SubcontractFields` is the single place (validation rules, typing, merge); controllers call `SubcontractFields::validate()`. Test: `sub_fields.php` (30).

## 12. Import rules added after the review of the live data

* an empty row (no description and no number) is skipped, not imported;
* a heading typed in column A on the first item's row starts a new section;
* a line that exists only in a sheet's SUMMARY block (the "LOAN" lines of SAID.HAMAD and SCAFFOLD) is imported as its own group, because it is part of the sheet total;
* spaces are collapsed; unit spellings added (Bag, Set, Box, RM, Month, Pers/ day, PCSS...);
* every section subtotal is checked against the rows its own formula adds up (none of the 44 imported sheets has a broken subtotal).
Known differences that stay open on the Reconciliation page: DERM ELEV (mixed currency, the workbook SUMMARY counts only its TZS part) and MASOUD JUMA (a 1,560,000 section that is in the detail but missing from the sheet's own SUMMARY block).

## 13. Speed

`SchemaCache` (hasTable/hasColumn remembered) removed about 100 ms of catalogue queries from every WIMS page; the Subcontractor Register no longer runs a query set per contact; the dashboard no longer loads users one by one; big agreements open their groups on demand (more than 80 items: collapsed); indexes added (`2026_11_11_100100`).

## 14. Independent code review (2026-10-18): what was fixed

A second reader went through the whole module; verified defects fixed and covered by `sub_review.php` (21 checks):

* a Rejected voucher can no longer be corrected and sent again once its subcontract payment was cancelled (it would have paid the request twice); a voucher can be started only for an agreement the user may see and only while the agreement is payable;
* an approved reduction cannot take the contract value below what is already paid or approved; variation approval locks the agreement first, like a payment request;
* the person who SUBMITS a request or variation is recorded (migration `2026_11_11_100200`) and cannot review or approve it; a draft request can be edited, given documents or deleted only by its maker or an approver;
* amounts typed as "1e3", text, or so large they would overflow are refused with a message; typed amounts round half up; the approved amount is validated; an exchange rate has an upper limit;
* a person without the financial permission can edit a draft agreement without wiping its original amount; reconciliation and the import pages need the financial permission; the audit report needs the audit permission; the History tab no longer carries logged amounts;
* confirming an import twice at the same moment cannot import twice (batch locked); a reconciliation with no sheet total to compare is no longer marked as matched; a workbook part larger than 80 MB is refused;
* reports: the work schedule and group summary count only approved variations (like the agreement page); a statement filtered by line type keeps the true running balance; custom date fields accept only real dates, list fields only listed choices.

Known and left on purpose: the import is one transaction run in the request (about 15 s); the ledger formats sums through float for display (exact below 1e13); `SubcontractNotifier` texts carry amounts to people who get the alert; the payment voucher module itself (outside this module) can still be edited by users with its own permissions.

## 15. The archive (status Archived)

Every sheet of the workbook is in WIMS: the 44 live accounts and the 27 hidden / older-version sheets, imported with decision "Import into the archive". An archived agreement keeps all its items, groups, paid amounts and its balance, but it is read-only, cannot get requests, variations or vouchers, and is NOT in the totals of the dashboard, the register, the headline reports or an all-contacts statement. Where it shows: the Archived tab of Agreements (balance per row), the Archive card on the dashboard (balance per currency), the Archived accounts block on a subcontractor's page, reports when the status Archived is asked for, and its own statement. Managers can archive an Active/Completed/Terminated agreement without open requests and restore it (it returns to the status it had). Tests: `sub_review.php` (archive block), `sub_import.php`.
Old versions of an account that belong to a person already in the register are filed under the same contact (HAFIDH SULEIMAN, MOHAMED RASHID, KHATIB PAINTER...); the project labour sheets (SKILL LAB, ROOFING, MPENDAE, MWERA SH, MSA MARKET, HAILE, FC KINYASINI, GRILL PBA, SUBCONTRACTED (NEW), HAILE FOULWATER) are filed under the contact "PROJECT LABOUR ACCOUNTS (ARCHIVED)", their beneficiaries kept on every line.

## 10. Deployment (owner does this)

Back up the database. Upload the zip, run `php artisan migrate` (adds the tables, the voucher column, permissions and Feature Control rows), `php artisan permission:cache-reset`, `php artisan optimize:clear`. Give roles their abilities on the Roles page, then open **Subcontractors > Excel Import** and upload the workbook. The zip contains no workbook. Rollback: `php artisan migrate:rollback --step=1` removes the tables and the voucher column (restore the database backup for anything created since). Make sure PHP `memory_limit` is at least 512M for PDF exports.

## 16. Requests above the balance, dashboard polish (2026-10-18)

- A payment request above the agreement's balance (headroom) is still saved and submitted, but the person is told every time: a live red note on the request form (also shown at once when the agreement has no balance), a flash note after save/submit, and a banner on the request page while it waits. The excess is carried forward as a negative balance once approved (approval over the balance still needs the existing override permission and reason). The agreement page shows "Negative balance carried forward" under a negative headroom.
- Dashboard: buttons and quick-access tiles carry shadows; the Archive card opens the archive when clicked anywhere, its button has an icon and maroon colour, and the archive balance is maroon.

## 17. Excel tie-out, one button system, links to QR, Signatories and the Launcher (2026-10-18)

- **Excel SUMMARY tie-out.** Reports > "Excel Summary Tie-Out" (also the "Compare With Excel" button on the dashboard) ties the TOTAL of the workbook SUMMARY sheet to the dashboard TZS total, line by line: a subcontractor listed twice on the SUMMARY (DERM ELEVATOR) is counted once; an account kept in another currency (OZTI, USD) is never added to TZS; a SUMMARY line that differs from the account's own sheet (MASOUD JUMA, +1,560,000) follows the sheet; active accounts that are not on the SUMMARY (8 of them) are added. It also lists the SUMMARY lines where the typed balance is not contract minus paid (YASIN, SAID/HAMAD, MOHAMED RASHID, KHAMIS GRILL; net -1,136,000): WIMS always uses contract minus paid. Service: `SubcontractTieOut`; the import now stores `summary_balance` per sheet.
- **Money.** Every amount in the module shows its currency, thousands separators, bold maroon, red when negative (`--sc-money`, `m()` in `sc.js` adds the currency of the record or the one given). Progress rings are smaller.
- **Buttons.** All buttons are 36px high with an icon; soft buttons are a quiet blue tint, tones (`t-teal`, `t-slate`, `t-green`, `t-amber`, `t-violet`, `t-red`, `t-maroon`) only mark meaning (print, download, suspend, delete, archive). Checkboxes share one style with a shadow. Same rules apply to Accounts Tracking and External Stock pages.
- **Tables.** Every table row that has a record opens it (click and Enter); a find field sits above client-side tables (`tableFind` in `sc.js`); figure tiles and summary cards open or filter something.
- **QR codes.** New kinds: subcontract variation, account transaction, account transfer (agreement, request and External Stock were already there). A code made for an Accounts Tracking record is stored under the WIMS account, not the tracked bank account.
- **Signatories.** New documents: subcontract agreement, variation, payment request (approval based: Prepared/Requested, Reviewed, Approved By), External Stock movement, Accounts transaction and transfer, and generated reports (`subcontract_report`, `external_stock_report`, `acct_report`: Prepared By = the person who generated it). The common report print shows the block (`ReportSignatures`); the External Stock print uses it too. Setup is seeded by migration `2026_11_13_100000`; the Document Setup page lists them.
- **Launcher.** Search now finds subcontract agreements, payment requests, variations, External Stock movements and account transactions (only what the person may see, no amounts in the result line).
- Tests: `ver/sub_link.php` (29).

## 18. Independent money audit (2026-10-18)

Every figure was recomputed from the raw tables (bcmath, row by row, never through the ledger) for the 71 agreements of the live copy and the demo database, and checked against the ledger, dashboard, register, agreements list, statements and the report totals; the workbook was re-read with openpyxl and compared row by row (4,989 rows: all equal except the US dollar block of DERM ELEV, converted at 2,508 on purpose). Defects found and fixed (regression checks in `ver/sub_audit.php`, 177 checks; `ver/audit_indep.php` is the read-only recomputation for the live copy):

* the ledger formatted sums through a float: above about 7e13 the cents were wrong (now exact decimal strings); money columns and tiles of the reports are formatted exactly too;
* the dashboard totals covered only Approved / Active / Completed / Suspended agreements while the register and the agreements list covered every agreement that is not a draft, cancelled or archived (Terminated, Submitted and Under Review were missing from the dashboard): one population now;
* the tie-out counted Draft and Cancelled agreements as "not on the SUMMARY" and stopped tying; it now leaves them out like the dashboard;
* reports: the status filter of Payment Requests, Variations, Voucher History and Reconciliation was applied to the agreement as well (listing nothing); Voucher History and Currency ignored the date range; the Currency "Paid" total included payments not yet paid and voided ones;
* a payment whose voucher was deleted was still counted "in a voucher, not paid";
* a tolerance typed as 1e3 on the Settings page made every approval fail with a server error: settings now accept plain amounts only and an old bad value counts as 0.00;
* quantity and rate were cut (not rounded) to 4 places for the amount but rounded when stored, so quantity x rate could differ from the amount by a few cents; both are now rounded half up once and the amount is calculated from the stored values;
* the TZS equivalent of a foreign payment and the exchange rate of an import block are limited so they fit the columns.
Left as they are, for the owner's decision: Submitted / Under Review agreements are included in the contract totals; Suspended and Terminated agreements are in the Register and Financial Summary but not in Outstanding Commitments / Outstanding Payables; the tie-out compares at whole currency units (a 0.01 rounding difference still ties).

## 19. Opening balances and the reset (2026-10-18)

Menu: **Subcontractors > Opening Balances** (feature `subcontract_opening`; permissions `read-opening-subcontracts`, `manage-opening-subcontracts`, `reset-opening-subcontracts`; all of them also need `view-financials-subcontracts`).

**The model.** Each agreement starts from one pair of figures: the **contract value** (agreement `original_amount` plus approved variations) and **opening paid** (ONE counted `subcontract_historical_payments` row of kind `opening_paid`, dated by the agreement's `as_at_date`). Nothing in the ledger formulas changed:
`revised = original + approved variations`, `paid in total = vouchers paid + counted opening line`, `outstanding = revised - paid in total`. New payment requests are measured against `revised - paid in total`; a request above the balance is still allowed and carries a negative balance forward.

**Setting an opening balance.** Opening Balances > an account > Opening Figures: contract value, paid to date, as-at date, note. Saving replaces the account's opening line (it never adds a second one), refuses letters, `1e3`, negatives, more than 2 decimals after rounding and a contract below the approved variations, and logs before and after. A paid amount above the contract is allowed (negative outstanding).

**Previous data.** The same page takes the Excel sheet (or CSV, PDF, pictures, Word) up to 16 MB each, with a note. **Preview** opens a new tab: Excel and CSV as readable tables (sheet tabs, first 400 rows, 24 columns), PDF and pictures inline, other files a clear message with a Download button. Previews, downloads and removals are logged. Files are only reachable through their own account.

**Reset To Opening Balances** (Super Admin with `reset-opening-subcontracts`, own password, the word RESET and an as-at date). For every account it keeps the contract value and the paid total and replaces everything else with the single opening line. Removed: imported payment lines, payment requests and lines, voucher payments and allocations, variations and the work items they added, reconciliations. **Kept:** the agreements, their work schedules (items and groups), the import batch and sheets, the Payment Vouchers themselves (only the link to the subcontract payment is cleared). It first proves that every account's revised value, paid total and outstanding are identical before and after (otherwise the whole transaction rolls back), writes a JSON backup of the removed rows to `storage/app/subcontract-resets/<stamp>/`, refuses while approved-but-unpaid or in-process money exists (unless forced), logs it and tells the administrators.

**Report.** `Opening Balances` (group Balances) with print, PDF, Excel and CSV. Catalogue: 27 listed reports plus 2 single-record documents.

**Run on the local live copy 2026-10-18** (backups: `wims-backups/*_before_opening_reset_20261018_2254.sql`): 71 accounts, 7,239 imported lines and 71 reconciliations collapsed into 71 opening lines; every account identical before and after; tie-out still true; Active TZS contract 12,324,059,392.68, paid 10,347,573,453.72, outstanding 1,976,485,938.96; USD 64,000 / 51,200 / 12,800.

Tests: `ver/sub_opening.php` (51 checks, demo database).

## 20. Project Name Mapping (2026-10-18)

Menu: **Subcontractors > Project Mapping** (permission `map-projects-subcontracts`, Super Admin always). It lists every distinct project or location name typed in the Excel work groups (139 on the live copy) with how many accounts and items use it and its value, and lets you choose the Project(s) / Warehouse(s) (warehouse) for each, by a searchable list or by uploading the filled template (the Project(s) / Warehouse(s) column is found by its header, falling back to column I; the Excel name falls back to column B; a warehouse code also matches).

Choices are **drafts** until **Confirm Mapping** (own password). Confirming applies every waiting mapping in one transaction, is safe to repeat, and picks up groups from a later import. An agreement gets a project only when **all** its groups are mapped to the same project and it had none (or one derived earlier); a project chosen by hand is never overwritten and a derived one is taken back if the groups later differ. Groups named "Original works" or "Additional works" count as groups too, so they need a mapping before an agreement gets its project (say if they should be skipped instead).

After confirming, the agreement page shows each group under the Project(s) / Warehouse(s) name with the Excel name as a small "Excel: ..." label, the project field and the agreements list show the mapped project(s), the schedule search also finds the Excel name, and the work item schedule, group summary, payments by work location and agreement detail reports use the mapped name. Migration `2026_11_15_100100_subcontract_project_mappings`. Tests: `ver/sub_mapping.php` (137 checks).

Deployment: migrations to run in order `2026_11_14_100000`, `2026_11_15_100000` (opening permissions), `2026_11_15_100100`; then `permission:cache-reset`, `optimize:clear`. The reset is a deliberate action in the page; it is not run by any migration.

## 21. Alerts page (2026-10-18)

Menu: **Subcontractors > Alerts** (feature `subcontract_alerts`; permissions `read-alerts-subcontracts` to see, `resolve-alerts-subcontracts` to acknowledge, assign, escalate, resolve and run the check). Same design as Financial Alerts in Accounts Tracking: counts, category tabs, filters, severity chips, an action row per alert. The old "Alerts" menu item (who is told, on which channel) is now **Alert Workflow**.

**What raises an alert** (hourly `subcontracts:sweep`, or the **Check now** button; thresholds in Subcontract Settings): an account paid above its contract value (balance; critical when more than 10% over, otherwise high), an account with 10% or less of its contract left (low), approved money not paid after 7 days (payment), a payment request, variation or agreement waiting longer than the review hours (approval), an agreement past or near its expected completion (contract), approvals refused or approved above the contract value with an override (control, raised at the moment it happens and kept until a person deals with it), and ONE setup alert for agreements that have no Project(s) / Warehouse(s) (points to Project Mapping).

**Behaviour.** One alert per condition: the sweep only counts it again and tells people again after the cooldown (24 hours). When the condition goes away the sweep closes the alert itself. High and critical alerts also go through the workflow page event "Serious Alert Raised" (to holders of `resolve-alerts-subcontracts`). Resolving a high or critical alert needs a written note; escalating raises the severity one step and reopens it. People without `view-financials-subcontracts` do not see balance, payment or control alerts nor any amount. Alerts about agreements a person cannot see are hidden. Every action is in the activity log. Migration `2026_11_16_100000_create_subcontract_alerts` (table + both permissions). Tests: `ver/sub_alerts.php` (54 checks, demo database).

## 22. Table headings, sorting, project creation, data load (2026-10-19)

**Headings.** Every Subcontract table has tall, softly coloured headings (blue key, sky text, green money, amber figure, violet date, teal status, grey end), the same on all pages. Agreements, Requests, Variations, Register, Reconciliation, Reports and Project Mapping headings are **click to sort** (once ascending, again descending, again the usual order). For the paged lists the server orders ALL matching rows and then cuts the page (`SortsLists`); Reports and Project Mapping sort in the browser. Plain tables (the Dashboard, forms) get the same colours from `resources/css/subcontracts.css` ("Plain table headings").

**Payment request amount.** `requests/create` fills "Amount requested" with the balance left on the chosen agreement (headroom) until the person types their own figure.

**Project Mapping.** The column is named **Project(s) / Warehouse(s)** (the old "WIMS Project" wording is gone from the pages, the Excel template and the alerts). The template sheet "Projects and Warehouses" lists the choices; an uploaded older file with the "WIMS project" header is still read. Each row has a **Create** button (needs `map-projects-subcontracts` and `create-warehouses`): a modal pre-filled with the Excel name (name, code, category, for who, stage, project manager, phone, address, start/end date, site budget) creates an ordinary active Project(s) / Warehouse(s) (`POST subcontracts/project-mapping/project`), matches it to that name as a draft and refreshes the list.

**Loading the Subcontract data on another server.** `php artisan subcontracts:export-data` writes `database/data/subcontracts/*.json` (agreements, work groups and items, previous payments, import record, project mapping, the subcontractor contacts they use, and `manifest.json` with counts and ledger totals). `php artisan subcontracts:load-data [--dry-run]` loads them: refuses a database that already has agreements, matches every contact by name inside SubContractors (creates only the missing ones), leaves an unknown project or user empty, runs in one transaction and rolls everything back unless every count and ledger total equals the manifest. Without SSH: Super Admin opens `/commands/subcontracts_load_data` (check only) and then `/commands/subcontracts_load_data?confirm=yes`. Tested on a copy of the live database with half the subcontractor contacts removed (24 created, 25 reused, totals equal).

## 23. Completion verification and Contract Payment Requests (claims) (2026-11-23)

**What was already there.** Payment requests are per agreement (`subcontract_payment_requests` + `subcontract_request_items`), approved by `PaymentRequestController::transition` (maker-checker, headroom check with `SubcontractLedger::headroomFor`, override with `override-subcontract-limits` and a reason). "Start Payment Voucher" (`initiateVoucher`) creates one `subcontract_payments` row and opens the Payment Voucher form pre-filled; `PaymentVoucherController::store` links payment and voucher. `SubcontractLedger` is the one place that defines a figure. An agreement has a Complete action (status Completed, `actual_completion`).

**What this adds.** Several completed agreements of ONE subcontractor can be requested in ONE document (a *claim*, reference `SCL-2026-0001`), decided line by line, and paid by ONE Payment Voucher that allocates to each agreement. **The payable unit is the agreement: work groups are not paid on their own.** Nothing here moves money; only a Payment Voucher that is Authorised and marked paid does.

### 23.1 Completion state of an agreement
* Migration `2026_11_23_100000_subcontract_completion_and_claims` adds to `subcontract_agreements`: `completed_by_user_id`, `completed_at`, `completion_note`, `completion_verified_by_user_id`, `completion_verified_at`, `completion_verify_note`.
* `POST subcontracts/agreements/{agreement}/do/complete` (existing, ability `manage-subcontract-agreements`) now takes an optional `note` (or `reason`) and an optional `actual_completion` date (today when empty; a future date is refused) and records who / when / note. Completing again clears any verification.
* New actions `verify-completion` and `unverify-completion` on the same route (ability `verify-subcontract-completion`). Maker-checker: the person who completed the agreement may not verify it (a Super Admin may; the log entry carries `self_verified`). With setting `claims_require_evidence` verification needs at least one document attached to the agreement. Withdrawing a verification needs a reason and is refused while the agreement is on a Pending / Returned / Deferred / Approved claim line.
* Completing or verifying never creates a payment and never changes a paid figure.

### 23.2 Settings (Subcontract Settings, soft-coded, saved only when sent)
`claims_require_verification` (default on: a Completed agreement can be claimed only after its completion is verified), `claims_require_evidence` (default off), `claims_allow_partial_request` (default on: a line may ask for less than the whole balance).

### 23.3 Tables
`subcontract_claims` (header: contact, project filter, ONE currency, status derived from the lines, requested / approved totals) and `subcontract_claim_lines` (one line per agreement, unique per claim: requested and approved amount, status, decision reason / decider, the payment request it created, override fields, a snapshot of the figures when it was requested, its history). Link columns: `subcontract_payment_requests.claim_line_id`, `subcontract_payments.claim_id` / `claim_line_id`, `payment_vouchers.subcontract_claim_id`. The old unique index on `subcontract_payments.payment_voucher_id` ("one payment, one voucher") became a plain index plus two composite unique indexes (`payment_voucher_id` + `request_id`, `payment_voucher_id` + `claim_line_id`) because one voucher now carries several payments; the application still links a single payment to a single voucher exactly as before.

### 23.4 The rules (all in `App\Services\Subcontracts\SubcontractClaims`, server side)
* **Eligible** = status Completed, and verified when the setting asks, and with a document when the setting asks, and a balance left. Visible to the user only; money only with `view-financials-subcontracts` (a person who cannot see money cannot type an amount: the whole balance is requested, so balances cannot be probed).
* **Reservation.** A line that is Pending, Returned or Deferred in a claim that is not a Draft or Cancelled holds its amount: `SubcontractLedger` counts it in `requested_pending` (and shows it alone as `claims_pending`). `remaining_eligible_signed = revised - approved_work - requested_pending`; `remaining_eligible` is the same floored at zero. Rejecting or cancelling a line releases it. A Draft reserves nothing and is revalidated on submit. No other existing figure changed meaning.
* **Decisions** (`decide-subcontract-claims`; return also with `review-subcontract-claims`): approve (full or partial amount), reject (reason), return (reason; the requester corrects the amount and sends THAT line again), defer (reason; stays reserved, decided later). Several lines can be decided in one call; it is all or nothing. A person who made or submitted the claim cannot decide it (a Super Admin can; logged). A decided line cannot be decided again (only Deferred lines and Returned lines sent again).
* **Approval** locks the agreements (ascending id), checks the headroom exactly like a payment request (an override needs `override-subcontract-limits`, the setting `override_allowed` and a reason; refusals and overrides raise the same alerts and events) and **creates the ordinary Approved payment request of the agreement** (`claim_line_id` set, description "Contract payment (claim SCL-...)"). From then on paid / approved unpaid / commitment left, the reports, statements, ARIS and the dashboard see it as any other request.
* **Claim status** is worked out from the lines by `recalc()`: Draft, Submitted (lines pending, nothing decided), Under Review (some decided, some pending, none approved), Partially Approved (at least one approved and at least one other line not approved, pending or not), Approved (all non-cancelled lines approved), Returned, Deferred, Rejected, Cancelled. Payment progress (Unpaid / Partly Paid / Paid) is derived from the ledger, never stored.
* **Taking an approved line back** (`lines.reverse`, or cancelling its payment request on the request page, which the line follows) is possible only while no payment was started; the line becomes Cancelled and its amount is open again.
* **Voucher.** `startVoucher` (`initiate-subcontract-vouchers` + read) creates one `subcontract_payments` row per selected approved line (amount up to what is left on the line's request; for a non-TZS claim one rate and its date), only ONE open set per claim, then opens the Payment Voucher form pre-filled (`?subcontract_claim=<id>`). The voucher store locks all open payments of the claim, checks the subcontractor as payee and the exact TZS total of all of them, puts every payment on the voucher in one step and sets `payment_vouchers.subcontract_claim_id` (`subcontract_payment_id` stays empty). Payee and total cannot be edited later (compared with the sum of the payments). A voucher that is cancelled or deleted frees ALL its payments at once; a Rejected voucher can be corrected and sent again until a new set is started (which sweeps it). When the voucher is paid, each allocation is logged on its agreement and the claim, and `claim_paid` is sent. A partly paid line keeps its rest in `left` for a later voucher.

### 23.5 Permissions and Feature Control
New block `subcontract_claims` in the Roles page: `read-subcontract-claims`, `create-subcontract-claims`, `submit-subcontract-claims`, `review-subcontract-claims`, `decide-subcontract-claims`; and in the agreements block `verify-subcontract-completion`. Migration `2026_11_23_100100_register_subcontract_claims_permissions_features` creates them (Super Admin holds them) and the Feature Control row `subcontract_claims` (child of `subcontracts`, route prefix `subcontracts.claims.`; the pages and every backend request are blocked when it is off). Alert Workflow events: `claim_submitted`, `claim_returned`, `claim_decided`, `claim_voucher_started`, `claim_paid`. The alert sweep adds "contract payment request waiting for a decision" (no amount in it).

### 23.6 Reports and ARIS
Reports (30 listed + 3 documents): `completed_eligible` (Completed Contracts Eligible for Payment), `claims` (Contract Payment Requests with every line decision), `claim_allocations` (Contract Payment Voucher Allocations: each voucher split by agreement with instalments and what is left), and the document `claim_detail` printed by `claims/{claim}/print|pdf` in the WIMS report layout. The existing Approved but Unpaid, Actual Payments, Voucher History and the account statements already include the requests and payments created from claims (they are ordinary requests and payments). ARIS: "claims waiting for approval" and "which completed contracts are not yet paid" (permission checked; amounts only with view-financials).

### 23.7 Tests (demo database)
`ver/sub_claims.php` (321 checks: completion and verification, eligibility, drafts, reservations, mixed outcomes and every parent status, partial approval, override, double decisions, returned and deferred lines, one voucher for several lines, exact total, second voucher for the remainder, dead vouchers, USD, take-back, permissions one by one, Feature Control, reports, ARIS, alerts, audit log, and an independent recomputation of every figure from the raw rows) and `ver/sub_claims_conc.sh` (7 checks: parallel processes on committed demo data that is removed again, row counts of every table compared before and after). `sub_http.php` expects 30 listed reports (+3 documents).

### 23.8 Not done / limits
* QR codes for claims are not added (the QR registry counts 40 kinds that other tests fix). No signatory document type was added: the claim document is signed as a generated report.
* Print / PDF of a claim needs `view-financials-subcontracts` and `print-subcontract-reports`.
* Dashboard card for claims: not added (`DashboardController` provides no claim numbers; the waiting count is on the Contract Payment Requests page).

### 23.9 Pages (2026-11-23)
* `Subcontracts/Claims/Index.vue`: cards (waiting, requested and approved per currency), status tabs with counts, search, filters (subcontractor, project, currency, dates), sortable table (request, subcontractor, project, date, lines, requested, approved, paid, left to pay, status with a payment bar). `Claims/Form.vue` (create and edit a draft): subcontractor + optional project, the table of contracts from `claims.eligible` (JSON, `all=1` when "Show contracts that cannot be requested" is ticked; the first column group is contract, open to request and the amount, so it stays on screen on a tablet), a tick per contract, live validation of every amount in exact cents (above zero, not above the open balance, whole balance when partial requests are off), one currency per request (other currencies are greyed with the reason), a sticky bar with the number selected, the total and the warnings, Save draft / Save and submit. Without `view-financials-subcontracts` there are no amount fields and the whole balance is requested. `Claims/Show.vue`: header actions by `can`, figures, one card per contract line (requested, approved, revised value, previous payments, pending when requested, approved not paid, eligible when requested, still allowed by the contract, evidence link `agreements/{id}?tab=documents`, decision with reason and decider, earlier rounds, payment progress and vouchers); **review mode** (`can.decide`): one choice per line (approve full, approve partial, reject, return, defer), running total approved and counts per outcome, ONE "Confirm decisions" posting `claims.decide` (all or nothing, errors `line_<id>` shown on the line); one-line actions (approve, partial with override reason when above the contract, reject, return, defer, correct and send again, cancel line, take the approval back); the "Start Payment Voucher" panel (choose lines and amounts, exchange rate and date when the claim is not in TZS) posting `claims.voucher`; vouchers with their allocation per contract; documents; history.
* `Agreements/Show.vue`: Completion card (state, completion date, who, verifier, evidence count linking to Documents, remaining eligible amount; Mark completed / Verify completion / Withdraw verification in a note dialog posting `agreements.transition`), the claims of the agreement in the Payments tab, "Request payment (contract)" (opens the claim form with the subcontractor). `Requests/Show.vue` shows the claim a request came from. `Settings.vue` has the three claim switches. `PaymentVoucher/Form.vue` posts `subcontract_claim_id` and shows the allocations (contract, request, amount, TZS total) read-only; `PaymentVoucher/Show.vue` shows the claim link and its allocations. `Reports.vue` opens `claim` rows and labels the three new reports. Menu entries (feature `subcontract_claims`) and the Launcher record search `subcontract-claims` (`RauncherController`) were added. The controller's `eligible` answer got one extra field, `original` (the original amount, money viewers only). All new CSS is the block of `cl-` classes at the end of `resources/css/subcontracts.css`.
* Known quirk of the backend snapshot: when a returned line is sent again, "Other pending when requested" on the line still includes the line's own earlier amount (the snapshot is taken before the old reservation is released). It is informational only; no rule uses it.
