# External Stock (WIMS)

Built 2026-10-17. Not deployed.

## What it is
Stock that belongs to **External Personnel** (contacts in the category *External Personnel*) but is kept in our warehouses. They take some out (**Stock Out**), bring more in (**Stock In**), and may already hold stock when the module starts (**opening stock**). It is a separate module: **every movement is approval based**, and an approved one can get a **Gate Pass**, a **Delivery Note** and a **Transport Route**.

## Rules
* The stock is theirs, so it has **its own ledger** and never changes the company's stock figures. Balance of a person in a warehouse for an item = approved Stock In + approved opening stock - approved Stock Out.
* Nothing changes until a movement is **approved** (Pending Approval > Approved / Rejected / Cancelled). Waiting Stock Outs are shown as *promised*, so the same stock cannot be promised twice; a withdrawal cannot take more than the person holds.
* The person who recorded a movement cannot approve it (a Super Admin can).
* A **Stock Out records everything**: who collects (name, mobile, ID / licence), the driver and the vehicle (registered ones from the lists, or typed). All are required; they print on the Gate Pass.
* Cancelling an approved movement is a separate ability; an approved Stock In cannot be cancelled once its stock has been taken out or promised.
* Items with variants or serial numbers are refused for now; quantities are in the item's own unit.

## Where things are
| | |
|---|---|
| Menu | External Stock: Movements, New Stock Out, New Stock In, Balances and Reports, Alerts |
| Routes | `external-stock.*` (list, create, store, show, edit, update, approve, reject, cancel, availability, reports.index / print / pdf / csv / xlsx, gate-pass.store, delivery-note.store, transport-route.store, workflow) |
| Tables | `external_stock_movements`, `external_stock_items`, `transport_records.external_movement_id` (migration `2026_11_09_100000_create_external_stock`) |
| Permissions | read-, create-, update-, approve-, cancel-, print-external-stock, generate-transport-route-external-stock (Gate Pass and Delivery Note use the existing create-gate-passes / create-delivery-notes) |
| Global Feature Control | `external_stock` and its child `external_stock_reports` |
| Alerts page | `/external-stock/workflow`: waiting for approval, approved, rejected, cancelled, stock taken out (collector, mobile, driver, vehicle in the message) |
| Reports | stock held (balances), movement register, Stock Out with collector / driver / vehicle / Gate Pass / Delivery Note / trip, statement of one person: WIMS report layout, print / PDF / Excel / CSV, every export logged |

## Paperwork and transport
The Gate Pass and Delivery Note are the existing documents, issued from an **approved** movement (the pass starts from the driver and vehicle already recorded). **Generate Transport Route** logs the trip from the Gate Pass (needs a registered vehicle): a withdrawal goes warehouse > person, a deposit person > warehouse; opening stock is not a trip. One live trip per movement; the trip waits for verification like any other.

## Tests and samples
Scratchpad `ver/ext_test.php` (47 checks on the demo copy). `php artisan external-stock:samples [--remove]` makes two SAMPLE people and eight movements (no alerts are sent).

## Limits
Variants / serials not handled; no sub-unit conversion; a person's stock is per warehouse (a move between warehouses is a Stock Out and a Stock In); no automatic link to the company Check-ins / Checkouts.

## Added 2026-10-17

* **Stock In / Stock Out** are the visible names (the stored kinds are still `deposit` / `withdrawal`).
* **Project(s) / Warehouse(s) is optional on Stock Out.** Left empty, the server picks the first warehouse (by name) where the person's available stock covers every line; if no single warehouse covers it the form asks the user to choose or ask for less. Stock In and Opening stock still need a warehouse. The availability check without a warehouse shows what the person holds across all warehouses.
* **Location** comes from the contact of the External person (new `contacts.location` column, edited on the contact form, searched in the contact list). It is copied onto each movement when saved (`external_stock_movements.location`), shown read-only on the form, and printed on the record, Gate Pass, Delivery Note and the Transport Route label. Migration `2026_11_09_100100`.
* **Attachments** (PDF, images, office files, up to 8 MB, 10 at a time) on the form and the record page, with preview and download; add while waiting for approval (and later by people who may update or approve), remove only while waiting. Every add, view, download and removal is logged.
* **Print and Download** of one record (WIMS document layout with QR, status, collector, driver, vehicle and signatures): Print opens the same PDF that Download saves; both need `print-external-stock` and are written to the activity log and to Download / Print Logs. Routes `external-stock.print`, `external-stock.pdf`.
