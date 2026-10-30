# RANS ID & Pass Management (built 2026-10-30)

One module for four kinds of card, one card engine: **Staff IDs**, **Compound Worker IDs** (Aluminium, Carpenter and any category added), **Visitor Passes** and **Gate Passes of people** (not the goods Gate Pass of Delivery & Gate). Menu group "RANS ID & Pass", routes `id-passes.*`.

## The card
* 55 x 85 mm, front by category, **one shared back** (Terms, contacts, the card's own issue and expiry dates). Fonts supplied by the owner: Gebuk (names, titles, numbers, labels, dates) and Pill Gothic 600 (back text), embedded in the PDF.
* `CardRenderer` writes every box in millimetres as HTML; the browser preview and DomPDF show the same markup. **Resolution (target 800 pixels per inch, 55 x 85 mm = 1732 x 2677 px):** text, rounded shapes, the skyline and the big yellow arrow of the back are vectors; the RANS logo (front of passes, back, rotated on the Staff ID) is a vector SVG traced from the supplied logo (`resources/idpass/img/logo.svg`, `logo-up.svg`, copies in `public/images/idpass/` for the browser) so it is sharp at any size; the QR code is drawn with whole squares to about 830 pixels across 24.9 mm; icons and the arrow are tinted masks at 360 to 540 pixels (about 800 ppi); the wave texture is 1732 x 2677. The only picture that can be softer is the **photograph**, which is never enlarged: it is as sharp as the file that was supplied (the card frame needs 976 pixels across; the tool warns below 600). `resources/idpass/` holds fonts, artwork and `card.css`; `build_assets.py` rebuilds the artwork (Font Awesome Free 6.5.1 icons, CC BY 4.0). Public copies of the fonts are in `public/fonts/idpass/`.
* **Speed:** DomPDF places a plain RGB or JPEG picture quickly but loops over every pixel of a picture with transparency, so large transparent PNGs are avoided (the QR is plain RGB, the arrow and logo are vectors). One card PDF about 0.2 s, 60 cards in one file about 3 s (batches raise the memory limit to 1 GB; a 128 MB limit is not enough for 60 cards at once).
* Characters the card fonts have no picture for (for example the Turkish dotted I in Pill Gothic) are printed as the plain letter (`FontMeasure::safe`), never as a gap.
* **Names never leave their box** (`NameFitter`): a little smaller first, then middle names become initials ("Habiil Jamal Salim" -> "Habiil J. Salim"), smaller again, then the first name too, and only at the very end "...". Widths come from the font files (php-font-lib), so the preview and the PDF choose alike. An all-capitals name ("ASHA NASSOR SALIM") is shown in normal capitals.
* DomPDF quirk measured and compensated: it puts the baseline of a line at a fixed share of the line height per font (0.913 Gebuk, 0.862 Pill Gothic). `CardRenderer::BASE` holds the numbers; change them if a font is replaced (re-measure with a test page).

## Numbers
* Per category: prefix, digits, next number (RS001, AL001, CP001, V001, GP001 to start). Taken inside a transaction with the category row locked; `idpass_numbers` records every number ever given (unique per category, never reused, even for a revoked or expired card); the database also has unique `(category_id, number)` and `(category_id, seq_no)` on the cards. Tested with six processes issuing at once: 60 numbers, no duplicate.
* A number typed by hand needs `manage-id-numbering` and can never be one used before. The WIMS staff reference (STF-00001) is untouched and shown beside the RANS ID.
* One open card (pending, active or suspended) per person and kind. Renew keeps the number and token; Replace makes a new card (new number and QR) and marks the old one Replaced; Revoke is final.

## Visitor passes (a stock of numbered passes) and Gate Passes of people
* **Visitor Pass = a numbered blank card** (V001, V002 ...), the same plastic pass is handed to many visitors over time. Category flag `pooled` (Settings, on for Visitor). On Issue, "How many passes" makes up to 200 numbered passes at once; they are printed once and re-used.
* **Visitor Logs link**: the check-in form has "Visitor pass" (lowest free number suggested). The pass is taken at check-in (`visitor_visits.idpass_card_id`) and taken back automatically at check-out, cancel or correction. A pass that is out cannot be given to a second visitor (422). The visit page shows the "Visitor Pass" block and the pass page shows "With <visitor>" plus a "Who held it" tab (from / until / given by / taken back by) from `idpass_assignments`. Permission `assign-visitor-passes` (Super Admin and roles that can create visitor logs). The pass can also be given or taken back by hand on the pass page.
* **Gate Pass of a person** belongs to a Contact whose category is **Gate Pass**. The contact page shows "holds it since ..." and "Earlier" passes with dates and the reason they ended (Replaced, Revoked ...). Replacing a lost or broken pass keeps the old card in the history. Approval is maker-checker (`approve-people-gate-passes`).
* **Reprints**: a card that has been printed before needs a reason (lost, broken, new photo ...) for any new print, download or batch; the reason is saved as an event ("reprinted") and in the print history. First prints need none.
* **Colours and icons**: each category has its own colour and icon (Staff blue badge, Aluminium and Carpenter their own, Visitor, Gate Pass car) shown as a chip and a coloured row edge in every table, so mixed lists are distinguishable. Defaults come from the category band colour and kind (`IdPassCategory::uiColor/uiInk/uiIcon`).

## My ID (self service)
A signed-in user whose login is linked to a Staff record (staffs.user_id) sees **My ID** in the profile menu (and at `id-passes/my-id`): both sides of their own card (tap to turn over), status chip, number, name, title, dates. No permission is needed because it only shows the person's own card; nothing can be changed or printed from it. Shared prop `hasMyRansId` decides if the menu item appears (Feature `id_passes` must be on).

## Print and export
* Card PDF: 2 pages of exactly 55 x 85 mm (front, back) - checked with `pdfinfo`. A4 side by side (portrait 3 pairs, landscape 4 pairs per sheet, cutting marks, FRONT/BACK captions), A4 double sided (sheet of fronts, then sheet of backs with the columns mirrored: "flip on the long edge"), and the WIMS report page (cards at real size + details). Only **Active** IDs are printed.
* Print Centre: filters, tick or "all that match", preview of cards / sheets / files, batch of up to 2000 cards in files of 60 cards (a batch up to the "made at once" setting is made on the spot; a larger one after the response, with a progress bar). Run time on this machine: 63 cards in 3 to 5 seconds.
* Every print, PDF and batch is a row in `idpass_prints` (card, number and name as they were, user, action, layout, copies, batch, result, IP) and in the activity log. "Print initiated" = the PDF was made and opened for printing; WIMS cannot see the printer and never says paper came out.

## QR verification
The QR holds `/id-verify/{40 random characters}` (never a database number). Anybody who scans sees VALID / EXPIRED / SUSPENDED / REVOKED / REPLACED / NOT YET VALID, type, number, dates and the holder as "Hamad A."; a signed-in person who may read that kind also sees the photograph, full name, title, vehicle and host. Setting "QR verification" can require sign-in. Rate limited (60 a minute), unknown or malformed tokens give the same "not found" page.

## Wiring
* Permissions (7 blocks, 36): module `read-id-passes`; per kind read / assign or create / update / print / download / revoke (+ approve for gates); Print Centre `access-id-print-centre`, `batch-print-ids`, `batch-export-ids`, `read-id-print-history`, `read-id-reports`; settings `manage-id-settings`, `-templates`, `-numbering`, `-qr-settings`, `-notifications`. The person Gate Pass permissions are named `*-people-gate-passes` because `read/create/print-gate-passes` already belong to the goods Gate Pass.
* Global Feature Control: `id_passes` and `id_staff`, `id_compound`, `id_visitor`, `id_gate`, `id_print_centre`, `id_batch_print`, `id_pdf_export`, `id_settings`, `id_reports`, `id_verify`. Switching one off blocks the pages and the endpoints, keeps every record. (Super Admin passes the route gate by WIMS policy.)
* Launcher/menu (`menu.js`), Roles and Permissions, Alerts page `id-passes/workflow` (issued, approval needed, approved, rejected, expiring, expired, suspended, revoked, replaced), activity log module "RANS ID & Pass Management", bell module `id_passes`, daily command `id-passes:watch` (06:10: expiry notices once, clean-up of temporary files), ARIS tool `IdPassTool` (counts, who has none, lists by category/status/period, expiring/expired, last printed by whom) with native question patterns.
* Staff profile: "RANS Identification" block (number, status, dates, last printed, times printed, View ID, Assign RANS Staff ID). Contact edit page: panel "RANS Identification" (open the ID or assign one).
* 15 reports (registers, active, expired, suspended/revoked, issuance, print history, by issue date, by expiry date, gate passes by vehicle and by authorisation) with print, PDF and CSV.

## Deploy
1. Deploy as usual (cPanel Git: Update from Remote, Deploy HEAD). The deploy copies `resources/idpass` and `public/fonts/idpass`, runs `migrate` (`2026_12_20_100000_create_id_passes`, `2026_12_21_100000_idpass_pool_and_assignments`) and clears the caches.
2. Run both migrations on the **simulation** database too (they also seed the five starting categories, the Gate Pass contact category and grant the permissions to Super Admin).
3. Grant the permissions to the people who need them on the Roles screen (only Super Admin has them at first). The scheduler must run every minute (daily expiry watch).
4. The server needs the `gd` extension (QR, photo and arrow pictures). No Chrome, Node or ImageMagick is used at run time.

## Rollback
`php artisan migrate:rollback --step=1` drops the six `idpass_*` tables. Photos are in `storage/app/idpass/photos`, files of batches in `storage/app/idpass/batches` (deleted after 30 days). The permissions and features stay (harmless) unless removed on the Roles and Feature Control screens.

## Limits (read before relying on it)
* The camera capture in the photo tool needs HTTPS or localhost and a camera: tested only as far as the code path (no camera in the test browser). Choosing a file, cropping and the "small picture" warning were exercised.
* Visitor Passes carry only the number and QR (no name, they are re-used); the QR page names the current holder. Gate Pass cards show the holder's name, vehicle and validity but no photograph (it is kept and shown on the verification page for signed-in staff).
* Only real contacts of category Gate Pass (and the compound worker categories) are searchable when issuing those cards; a new person must be added to Contacts first.
* The logo is the 819-pixel `public/images/logo.png`: about 700 dots per inch at the size it is printed; a vector logo would be crisper.
* Page 2 of the Staff front uses the supplied vertical logo made by rotating that file.
* Printing is only ever claimed as "PDF generated" or "print initiated".
