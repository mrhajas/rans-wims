# Travels

Records the travels of staff and users, who issued the ticket and the visa, what each cost, and what each ticket agent is owed. Menu: **Travels** (Travels, New Travel, Ticket Agents, Reports, Settings, Alerts).

## A travel
- One record, **one or more travellers** (a staff member, a user, or someone typed in). Each traveller has a mobile, a ticket number, a ticket price and (when there is a visa) a visa price. The totals are worked out on the server.
- **Where**: from / to (city and country), departure date (a future date is normal), return date (return trips), the day the ticket was booked.
- **Flight**: airline and flight number (and the return flight), booking reference (PNR).
- **Ticket and visa**: the ticket agent and the visa issuer are **Contacts** in the categories `Ticket Agent` and `Visa Agent` (the issuer of a visa may also be a Ticket Agent). The money is in one currency per travel (currencies are a setting).
- **Local / International** is worked out from the country they go to against the home country (setting, default Tanzania). Reports filter by it and by country.
- **Approval based**: Pending Approval -> Approved / Rejected, or Cancelled. The person who recorded it cannot approve it (a Super Admin may). Only an approved travel is owed to the agent. An approved travel can still have its flight and dates corrected (never the money or the people); a cancelled one stops being owed.
- Attachments (polished dropzone), QR code (scan opens the record), Print and PDF with the WIMS letterhead, every action in the activity log, Print / Download in the Download / Print log.

## What an agent is owed (`TravelLedger`)
`balance = opening balance + approved tickets and visas they issued - payments that are paid`, per currency. Nothing is stored as a balance.
- **Opening balance**: what was owed before the module started, with a date. Travels booked before that date are already inside it.
- **Payments are made only through a Payment Voucher.** On the agent's page, **Make a payment** starts a *Draft* voucher (type `Travel Agent`, payee = the agent contact) and reserves the amount ("Pending Voucher"). The voucher is submitted, approved, authorised and marked paid in the Payment Vouchers module. When it is marked paid the payment becomes **Paid** and the balance falls (the date, method and reference come from the voucher). A voucher that is rejected, cancelled or deleted **releases** the amount (kept as history). A foreign currency balance is paid in TZS at a typed rate: the voucher carries the TZS total, the balance falls by the foreign amount.
- The **Payments** tab lists every voucher: asked on, paid on, voucher number and status, amount, balance after it, who asked, who paid, and a detail page with the voucher stages.
- Hooks in `PaymentVoucherController` (edit sync, delete, record payment) and `UpdateTransportRecordsFromVoucher` (reject / cancel) call `TravelVoucherService`. The voucher line carries `payment_voucher_items.travel_agent_payment_id`.

## Reports (`Travels > Reports`, print / PDF / Excel / CSV, all logged)
Travel register, Upcoming travels, Travels by country, by destination, by traveller, Ticket agent balances, Statement of one agent, Agent payment history. Filters: country, local / international, agent, currency, status, dates. The printed report shows who generated it, when, the filters and a QR code that reopens the same report.

## Alerts, settings, access
- **Alerts** page (`travels/workflow`, Super Admin): approval needed, approved, rejected, cancelled, departing soon (command `travels:remind`, daily 07:20, on the days set in Settings), payment requested / paid / released.
- **Settings** (`manage-travel-settings`): currencies, kinds of travel, home country, reminder days. All soft-coded.
- **Permissions** (Roles page, group Travels): read, create, update, approve, cancel, print travels; read-travel-agents; create-payment-voucher-from-travels; manage-travel-agents (opening balances); manage-travel-settings.
- **Global Feature Control**: `travels`, `travel_agents` (route prefix `travels.agents.`), `travel_reports` (`travels.reports.`).
- **Launcher**: records `travels` and `travel-agents`. QR kinds `travel`, `travel_payment`.
- Who sees which travel: the person who recorded it, a traveller on it, and people who approve / cancel / see agent accounts; Super Admin and view-all see everything.

## Operations
- Migration `2026_11_25_100000_create_travels` (tables `travels`, `travel_travellers`, `travel_agent_openings`, `travel_agent_payments`, column on `payment_voucher_items`, voucher type `Travel Agent`, permissions, features). Run on the live database and the simulation database.
- Samples: `php artisan travels:samples [--remove]` (agents, six travels in every status, openings, three voucher payments).
- Tests: scratchpad `ver/travels_test.php` (98 checks, including the real Payment Voucher flow).
