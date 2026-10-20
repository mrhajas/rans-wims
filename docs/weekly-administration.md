# Administration (weekly document)

Built 2026-10-20. Routes `weekly-administration.*`, features `weekly_administration`, `administration_charity`, `administration_reports`, workflow (Alerts) module `administration`, log names `Administration` and `Administration Charity`.

## What it does
- One document per week: numbered lines (No, Description, Beneficiary, Amount), an optional Charity section, a total (lines + charity when included).
- Three stages: **Requested** (prepared) -> **Approved** (`approve-weekly-administration`) -> **Authorized** (`authorize-weekly-administration`). Reject at either stage with a reason; the maker corrects it and it goes back to Requested. Cancel needs a reason.
- Separation: the maker cannot approve or authorize their own document, and the approver cannot also authorize it (a Super Admin may).
- Start from the **template** (the standing items, in order, with usual beneficiary and amount) or from **zero**. Lines can be added, removed and moved. Every typed line is remembered as an item (same name = same item) so reports can follow it.
- **Charity** sub-module: a list of people / places with a monthly amount. A document pulls the active ones (wording `CHARITY - :name FOR :month`, set in Settings); each pulled amount and wording is editable on the document without changing the list. Including or removing the section changes the document total.
- **Duplicate**: needs `duplicate-weekly-administration` + `create-weekly-administration` and the password of the person doing it (not only a Super Admin). The copy keeps lines and charity, the date moves one week on (never into the past), and it starts again at Requested.
- Beneficiary ("Requested by") defaults to the person making the document and can be any active WIMS user. The date defaults to today.
- **Send** (`send-weekly-administration`): PDF with QR attached, to typed emails (comma separated, max 15) and/or all active Super Admins; each sending is kept on the document and logged.
- Print / PDF in the WIMS layout (letterhead, watermark, QR, signatures: Requested / Approved / Authorized).
- Reports (8): register, expenses by item, item month by month, every line, totals by month, by beneficiary, charity detail, charity by person. Filters: dates (this month / last month / quarter / year shortcuts), status, item, beneficiary, requested by, section, charity, amount range, search. Money reports count Authorized documents unless a status is chosen. Print, PDF, Excel, CSV; every export logged.

## Permissions (Roles page, group "Administration (Weekly)")
read / create / update / approve / authorize / cancel / duplicate / print / send / view-all, `manage-administration-items`, `read-administration-charity`, `manage-administration-charity`, `manage-administration-settings`. Only Super Admin has them after the migration. Without `view-all-weekly-administration` (or approve/authorize) a person sees only documents they made or are the beneficiary of.

## Logging
Every action writes the activity log (requested, updated, approved, authorized, rejected, cancelled, duplicated, refused duplicate password, sent by email, printed, downloaded, report exported, charity pulled, item and charity changes). Prints and downloads also land in Downloads & Prints automatically. Smart Brief reads these under its own module `weekly_administration` (needs `read-weekly-administration` / `read-administration-charity`). QR kind `admin_document`; Launcher entries `administration` and `administration-charity`; menu group "Administration".

## Deploy
Migration `2026_11_28_100000_create_weekly_administration` (tables, permissions for Super Admin, the three features, and the starting template of 26 lines = 3,959,000.00 from the owner's weekly sheet). `php artisan administration:samples` (local review samples + sample Charity list) and `--remove`.
