# Accounts Tracking (WIMS)

Built 2026-10-17. Not deployed. This file documents what exists, how it is wired, how to deploy it and how to roll it back.

## 1. What it is

A financial tracking module for bank accounts, cash in hand and other accounts. One ledger of every money in and out, transfers between company accounts (same or different currency), multi-currency with an approved exchange rate register, reconciliation (cash count and bank statement), alerts, 32 reports, an append-only audit trail and a link to Funds Allocation.

Rules that hold everywhere:

* A balance is `opening balance + posted money in - posted money out`. Only `posted` (and `reversed` originals, which are cancelled by their own reversing entry) count. Draft, submitted, approved, rejected and cancelled items never touch a balance.
* Money is handled as decimal strings (`App\Support\Accounts\Money`, bcmath). No floating point. Each currency has its own decimal places.
* Amounts in different currencies are never added together. The dashboard shows each currency in its own money; the reporting-currency view is a labelled conversion at approved rates, with the rate and time shown, and missing rates are reported, not guessed.
* A posted entry is never edited or deleted. Corrections are a linked reversal or an adjustment.
* A person cannot approve what they created or submitted (setting `maker_checker`, on by default). A Super Admin can only do so if `super_admin_self_approval` is switched on, and it is audited.
* Transfers are never income or expense. A cross-currency transfer needs an approved rate; the credited amount is entered and any difference from amount x rate is recorded as an exchange difference (must be explained if above the tolerance).
* Funds Allocation: an allocation is a plan and never changes a balance. A PAID line can be recorded once as a payment from a chosen account (unique key `fund_allocation / item_line / id`).

## 2. Separate schema

All Accounts Tracking data is in its own database connection `accounts` (config/database.php), database name from `DB_ACCOUNTS_DATABASE`. If that variable is not set it falls back to the main database (so nothing breaks), but the tables are named `acct_*` and are never mixed with WIMS tables. The two migrations:

| Migration | Connection | Does |
|---|---|---|
| `2026_11_07_100000_create_accounts_tracking_schema` | `accounts` | creates the 13 `acct_*` tables, seeds TZS (reporting) and USD, categories, approval rules |
| `2026_11_07_100100_add_accounts_tracking_abilities_and_features` | main | creates the permissions, grants them to Super Admin, registers Global Feature Control rows |

(Laravel's `migrations` table stays in the main database for both.)

### Tables (database `accounts`)

`acct_accounts` (code, name, kind, currency, encrypted account number + masked copy, limits, custodian, opening balance, cached balance, status), `acct_currencies`, `acct_exchange_rates` (rate decimal(20,8), type, source, validity, pending/approved/rejected), `acct_categories`, `acct_transactions` (the ledger; unique reference, unique idempotency key, unique source key, links to transfer / reversal / reconciliation / funds allocation), `acct_transfers`, `acct_approvals` (approval trail), `acct_approval_rules` (thresholds per currency), `acct_attachments`, `acct_reconciliations`, `acct_recon_items`, `acct_alerts`, `acct_audit` (hash-chained, append-only).

## 3. Permissions (Roles page, block "Accounts Tracking")

view-accounts-tracking, read-acct-accounts, view-all-acct-accounts, create-acct-accounts, update-acct-accounts, manage-acct-cash, manage-acct-banks, deactivate-acct-accounts, close-acct-accounts, view-acct-sensitive, read-acct-transactions, read-acct-transfers, view-acct-attachments, export-acct-transactions, view-all-acct-departments (reserved, not yet used), manage-acct-currencies, create-acct-rates, approve-acct-rates, manage-acct-approval-rules, perform-acct-reconciliations, approve-acct-reconciliations, read-acct-alerts, resolve-acct-alerts, read-acct-reports, view-acct-audit, manage-acct-settings, submit-acct-transactions, reverse-acct-transactions.

**Money In, Money Out, Transfer and Adjustment are approval based, and each has its own three abilities:**

| Kind | Create | Approve / reject | Post (changes balances) |
|---|---|---|---|
| Money In (receipts) | create-acct-inflows | approve-acct-inflows | post-acct-inflows |
| Money Out (payments) | create-acct-outflows | approve-acct-outflows | post-acct-outflows |
| Transfer | create-acct-transfers | approve-acct-transfers | post-acct-transfers |
| Adjustment | create-acct-adjustments | approve-acct-adjustments | post-acct-adjustments |

Every one of them must be approved before it can be posted, whoever created it: the approval rules can ask for MORE than one approval (by amount, currency and account) but never for none. The person who created or submitted something cannot approve it (setting), and approving is not the right to post. The Approvals inbox, the dashboard counts and the alerts about what is waiting only show or reach people for the kinds they may decide on.

Only Super Admin holds them after the migration. Without `view-all-acct-accounts` a person sees only accounts they look after (custodian) or are named on. Every action is checked on the server in the controller and again in the posting service; hiding a button is never the protection.

## 4. Global Feature Control

Parent `accounts_tracking` plus children `acct_dashboard`, `acct_accounts`, `acct_transactions`, `acct_transfers`, `acct_currencies`, `acct_reconciliation`, `acct_alerts`, `acct_reports`, `acct_audit`, `acct_funds` (route prefixes `acct.*`). Switched off: the pages redirect to "disabled", the JSON/API answers 403, the menu hides the group, and all data is kept. Super Admin still passes (as for every WIMS feature).

## 5. Routes

85 routes named `acct.*` under `/accounts-tracking` (see `routes/web.php`, block "Accounts Tracking"): dashboard; accounts (index, create, store, show, edit, update, status, link-funds, repair); transactions (index, create, store, show, edit, update, submit, approve, reject, cancel, post, reverse, attachments add / download / remove); transfers (same plus `rate` lookup); approvals; currencies and rates; reconciliations (create, store, show, explain, import, items, match, submit, approve, reject); alerts (read, acknowledge, assign, resolve, escalate); reports (index, print, pdf, csv, xlsx); audit (index, verify); settings (settings, approval rules, categories); funds (index, show, post-line); `acct.workflow` (Alerts page: who is told, on which channel).

State-changing routes carry per-route throttles. Posting is atomic (database transaction, row locks in account-id order, retry on deadlock) and idempotent (repeat posts return the first result).

## 6. Configuration

Settings page (stored in the WIMS `settings` table, key `acct_settings`): maker-checker, super-admin self approval, accounting date rule, closed-through date, large-transaction limits per currency, attachment-required threshold per currency, cash/bank difference needing two approvers per currency, pending-too-long hours, unusual-payment factor, alert cool-down, rate tolerance %, inverse rates, reconciliation overdue days, denominations per currency. Approval rules: how many approvals by amount range, currency, account and kind (strictest match wins; thresholds only compare within the rule's own currency).

Scheduled: `acct:scan` hourly (low balances, overdue reconciliations, items waiting too long, balance versus ledger check). `php artisan acct:scan --check` only reports balance mismatches (exit code 1 if any).

Alerts reach people through the module's Alerts page (`/accounts-tracking/workflow`), using the normal WIMS channels. Notification module key: `accounts_tracking`.

## 7. Audit

`acct_audit` is append only and hash chained (each row hashes the previous one). Audit > "Check integrity" walks the whole chain and compares every stored balance with the ledger. Writing is serialised so concurrent posting cannot fork the chain (see section 9).

## 8. Reports (32)

Balances (account balances, by currency, by type, on a past date, account statement), Transactions (register, money in / out by category, by month, by method, daily cash), Transfers and currency (register, conversions, exchange differences, rate register), Approvals and control (pending, rejected / cancelled, reversals, adjustments, large transactions, by user, turnaround, audit trail), Reconciliation (summary, cash and bank differences, unreconciled, bank exceptions, cash position), Accounts (low balance, dormant, register, Funds Allocation link). Each has filters, on-screen view, print, PDF, Excel and CSV in the WIMS report layout; every export is written to the activity log and the audit trail. Spreadsheet cells that start with `= + - @` are neutralised.

## 9. Tests (scratchpad, demo database only)

| Script | Covers |
|---|---|
| `ver/acct_core.php` | 33 checks of the engine: receipts, payments, separate currencies, transfers, cross-currency 100 USD at 2,500, pending, approval, posting, idempotency, reversal, cash difference, historical balance, integrity |
| `ver/acct_http.php` | 77 checks through the web layer: all 19 pages, 32 reports, exports, permission refusal (UI and API), sensitive number masking, maker-checker, double submission, reversal, attachments, transfers, cash count 500,000 vs 480,000, bank statement CSV import and matching, Funds Allocation, alerts, currencies and rates, settings, Feature Control (master and one component), integrity and audit chain |
| `ver/acct_conc.sh` | real parallel processes on committed data: the same payment posted from 8 processes posts once; six 30,000 payments from a 100,000 account post 3 and never go negative; four 200,000 transfers from 500,000 post 2; no duplicate references; audit chain intact |
| `ver/acct_perf.php` | 50,000 ledger rows: dashboard 0.3 s, transaction list 0.16 s, statement 0.09 s, 32-report previews under 2 s |

The concurrency test found and fixed a real defect: two simultaneous writers could fork the audit hash chain. The writer now holds a row lock on the last audit record until commit.

## 10. Deployment (owner does this; nothing is deployed from here)

1. **Back up first**: the main database and, if it exists, the accounts database (cPanel phpMyAdmin export or File Manager copy).
2. In cPanel > MySQL Databases create a database for accounts (for example `ranscoco_wimsacct`) and give the WIMS database user all privileges on it.
3. In the live `.env` add `DB_ACCOUNTS_DATABASE=ranscoco_wimsacct` (and `DB_ACCOUNTS_USERNAME` / `DB_ACCOUNTS_PASSWORD` / `DB_ACCOUNTS_HOST` only if they differ from the main ones).
4. Upload the cumulative zip, then run the migrations (the same way earlier ones were run). The two Accounts migrations are `2026_11_07_100000` and `2026_11_07_100100`. Earlier pending ones that are not live yet still apply (2026_11_05_100000, 2026_11_06_100000, 2026_11_06_100100 and anything older).
5. Clear caches: `php artisan permission:cache-reset`, `php artisan optimize:clear`. Make sure `storage/app` is writable (attachments are private files under `storage/app/acct-attachments`).
6. On cPanel the scheduler already runs `schedule:run` every minute; `acct:scan` is in it.
7. Log in as Super Admin, open **Accounts Tracking > Accounts Settings**, set limits, add the accounts (or "Bring in Funds Allocation accounts"), set opening balances, add exchange rates and have a second person approve them, then grant permissions to roles under Roles.

### Rollback

Switch the feature off first (Global Feature Control > Accounts Tracking) to stop use at once; data is kept. To remove completely: restore the backups of both databases and the previous code zip. Dropping the accounts database removes all financial data; do that only from a backup you have verified.

## 11. Sample data

`php artisan acct:samples` creates clearly marked "SAMPLE" accounts (code `SMP-...`) with posted, pending, rejected, transfer and cash-count examples, all through the real posting rules. `php artisan acct:samples --remove` takes them away again. It exists in the demo copy (`wims_demo` / `wims_accounts_demo`). It was deliberately not run on the live-like local database because posting and alerts notify real people.

## 12. Known limitations

* No live bank balances or bank feeds: reconciliation is by statement import (CSV with Date, Description, Reference, Debit / Credit or Amount columns) and manual matching.
* No one-click fix for a cash difference: the approver can propose an adjustment entry, which still needs its own approval and posting.
* Departments / projects are stored and filterable, but access scoping by department (`view-all-acct-departments`) is not applied yet; access is per account.
* Approval rules choose how many approvals are needed, not which role may give them: that is the matching `approve-acct-...` permission (see the table above).
* The sidebar does not show a pending-count badge; pending items appear on the dashboard, on Approvals, and unread alerts reach the bell through the normal notification centre.
* Exports above 50,000 rows are cut at 50,000 (the report says so). Print and PDF are cut at 500 rows, with a note on the page pointing to the CSV / Excel download.
* Rate sources are manual (no automatic provider).
* Integration with other WIMS modules (payment vouchers, salary, imprest) is by the Funds Allocation link only; other modules do not post to the ledger automatically yet, so there is no double posting by design.

## Independent formula audit (2026-10-18)
13 defects found and fixed (transfer reversals counted as income and expense, statement without opening balance, expired rate accepted on a transfer, limit bypass by editing a draft, receipt/payment kind against direction, value-date rule in reports, closed-period date formats, audit chain race, stale approvals, huge amounts and number parsing, negative amounts exported as text, inverse-rate exchange difference). Regression tests: `ver/acct_audit.php` (255) and `ver/acct_audit_conc.sh`. Owner decisions left open: future-dated entries change the stored balance at once while the as-of-today balance excludes them; reversals that would overdraw are blocked.

## Second audit (2026-11-21)
* A transfer is visible only to a person who can see BOTH of its accounts (lists, page, approvals inbox, dashboard counts and reports); the same holds for the Funds Allocation pages, which show only payments on accounts the person can see.
* Maker-checker, Super Admin self-approval and the closed period are changed only by a Super Admin, from their own card on the Settings page, with the password asked again (`PUT settings/controls`, behind `password.confirm`). Changing the reporting currency (`password.confirm`) and the balance check/repair (session password confirmation) ask for the password too.
* Idempotency keys count only for the same person and account. A Funds Allocation line uses a key that cannot be guessed; a cancelled or reversed payment releases its line so the line can be recorded again.
* Amounts whose value in the reporting currency would not fit the ledger columns are refused with a message when posting (and when a transfer is created); counted balances are capped; denominations must be positive numbers; a statement CSV may start with a byte-order mark and holds at most 5,000 lines.
* Alerts are numbered and inserted under one lock; the hourly scan raises a critical alert when a stored balance disagrees with the ledger, reads the open alerts once and makes new "waiting too long" alerts in batches with one message per batch.
* Reconciliation notes, imports, added lines and matching are written to the audit trail; refused page requests are written once per person, route and minute.
* Migration `2026_11_21_300000_accounts_tracking_audit_and_fund_line_indexes` adds `acct_audit (entity_type, entity_id, id)` and `acct_transactions (fund_item_line_id)` indexes.
* Left as a design decision: a posting dated inside an already approved reconciliation period is allowed (only the closed period locks dates).
