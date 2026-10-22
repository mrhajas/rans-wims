# Visitor Logs (built 2026-10-22, local only, not committed or deployed)

Reception module: a directory of visitors, one log per visit (check-in / check-out), and the letters, packages and documents received at reception.

## Where things are
- Pages `resources/js/Pages/VisitorLogs/` (Dashboard, Visits, Visitors, Items, Reports, Settings, `Parts/` pickers); controllers `app/Http/Controllers/VisitorLogs/`; models `Visitor`, `VisitorVisit`, `VisitorIncomingItem`, `VisitorOption`, `VisitorLogEvent`; services `app/Services/VisitorLogs/` (`VisitorSettings`, `VisitorNotifier`, `VisitorReports`); command `visitor-logs:watch` (every 30 min).
- Migrations `2026_12_08_100000_create_visitor_logs` (tables, seed lists, 31 permissions, 8 features) and `2026_12_08_110000_visitor_logs_roles` (Receptionist and Administration get their permissions; adds only).
- Routes all start `visitor-logs.`; the Alerts page is `visitor-logs.workflow` (shared engine, `app/Support/Workflow/Modules/visitor_logs.php`).

## Rules that matter
- Times are the **server's**: check-in, check-out, received and handed-over use `now()` in the WIMS timezone. Only a person with `correct-visitor-logs` / `correct-incoming-items` can set an earlier time, and a reason is then required and recorded.
- A visitor already checked in cannot be checked in again; a second check-out is refused (409) and changes nothing. Corrections and cancellations need a reason and keep the old and new values (`visitor_log_events`, never edited).
- Duplicates: same phone (last 9 digits) or same name at the same company is caught; "save anyway" needs `create-duplicate-visitors`.
- Sensitive visitor fields (ID type/number, nationality, address, emergency notes) are off by default (Settings), shown only with `view-visitor-sensitive`, and the ID number is encrypted in the database and never written to the activity log.
- Visits: `view-all-visits` sees all; otherwise only visits the person registered or was visited in. The person visited (and the recipient of an item) can always open their own record by the link in the alert.
- Items: "Delivered / Collected" is only set by **Hand Over** (who received it, when, who released it), never by a plain status change. Confidential items hide sender and subject (list, page, search, reports, exports, alerts) from people without `view-confidential-incoming-items`, except the recipient and the person who registered it.
- Lists (purposes, item types, item statuses, departments, visitor categories) are rows in `visitor_options`, edited in Settings; a flag gives an entry its meaning (asks for a description, first status, ready for collection, handed over, closed without hand-over).

## Projects, typed lists and photos (2026-10-22, second pass)
- **Attended from:** every visit and every incoming item says which Project / Warehouse it is attended from (`warehouse_id`, migration `2026_12_08_120000_visitor_logs_projects`). The choices are the projects the person is assigned to (a Super Admin / Admin with none assigned sees all). A person with ONE project starts with it; otherwise the field starts with the reception project (Settings, HQ OFFICE by default) when the person may use it, else they must choose. The list, the register, the item register, the reports and the exports can be filtered by project; a person with "all visits" / "read incoming items" sees only the visits and items of their own projects (plus ones with no project and ones addressed to or registered by them).
- **Typed lists:** Department / section, Purpose of visit and Type of item are dropdowns that also take a NEW entry: type it, save the visit or item, and it is added to the list (case-insensitive, no duplicates; an entry an administrator switched off is not brought back). New item types have no document / package meaning until an administrator sets it in Settings.
- **Take a Photo:** the visit and item forms and pages have the Site Diary camera (live camera or webcam, front / back, the phone's camera app as a fallback) next to the file drop zone.

## Contacts (third pass)
- Visitors and the senders of incoming items are **Contacts of the category "Visitors"**. A new visitor gets a Contact (an existing Contact with the same phone number is linked instead; the same name with another phone gets a tidily named new Contact), and editing the visitor updates it. Visitors that existed before are linked by migration `2026_12_08_130000_visitor_logs_contacts`.
- On the incoming-item form the sender is chosen **from Contacts** (any category) with a search; **Add Contact** opens a window that saves a new Contact in the category Visitors (the same name or phone is offered instead of duplicated). The item keeps the contact's name and phone as they were on the day. The recipient is chosen from Users.

## Fourth pass
- Roles (migration `2026_12_08_140000_visitor_logs_role_access`): Super Admin, Admin and Project Manager have all 31 Visitor Logs permissions; Receptionist and Engineers can log visitors and incoming mail & deliveries (register, check out, hand over, add visitors and contacts, history, reports) but not correct, cancel, see sensitive details or change settings. Engineers see their own visits (registered or visited) and the items of their projects.
- The person visited, the recipient, the sender (Contacts) and the visitor fields show a list to pick from as soon as they are clicked (the first 30 by name) and narrow as the user types. New forms start with Department "Management", Purpose "Business meeting", item type "Letter" (when those are still in the lists).
- Sample data: `php artisan visitor-logs:samples` (8 visitors, 13 visits incl. one long stay, a cancelled and a corrected one, 8 items incl. confidential, waiting too long, handed over, returned) and `--remove`. Written straight to the tables: nobody is notified.

## Notifications
Handled by the shared Notification engine, page "Alerts" (needs `manage-visitor-notifications`): add roles or people to any event, switch channels (in-app, SMS, email, WhatsApp), edit the words. Defaults: visitor arrived -> the person visited + Super Admins (in-app + SMS), item received / ready -> the recipient; "visitor left" and "item handed over" are off. Recipients' own contact book (WIMS Connect > Recipients) decides which numbers and emails exist; a failed SMS or email never stops the record being saved. `visitor-logs:watch` sends ONE informational reminder for a visitor still on the premises after N hours and for an item waiting more than N days (Settings).

## Reports (15)
Daily register, history by person, by company, by recipient, by department, currently on premises, check-in/out history, durations, letters and documents, packages and deliveries, awaiting collection, delivered, overdue, notification log, audit and correction history. Print, PDF, Excel, CSV in the WIMS report layout; exports are logged; some need extra rights (history, notification log, audit).

## Not built (said plainly)
- Department-based routing of notifications and escalation of unacknowledged alerts (the shared engine has neither); immediate-vs-queued is the engine's own behaviour, not a setting here.
- Signature capture on hand-over; automatic deletion by retention (the retention months setting is a recorded policy only; nothing is purged). Note: the generic activity log is cleaned after 365 days by the existing schedule, but `visitor_log_events` (the audit report) is permanent.

## Tests
Scratchpad `ver/visitor_test.php` (127 checks) plus the existing suites; phone / tablet / desktop checked in the browser.
