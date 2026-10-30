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

## How the ID is shown
* **Cut-out viewer** (`Parts/IdCutout.vue`): the card with no frame, floating, tilting towards the pointer, turning over with a tap; under it **Front, Back, Print, Download** (Print and Download only when the person may and the ID is active and not expired). Used on the ID page of every kind, on the **staff profile** ("RANS Identification" block: the card pictures are fetched after the page shows, from `id-passes/{kind}/{card}/faces`, so the profile stays light) and on My ID.
* **My ID** (self service, profile menu and `id-passes/my-id`): a signed-in user whose login is linked to a Staff record sees their own card. No permission is needed because it only shows their own card. A short scan animation plays first (card outline, QR squares assembling, laser sweep; click skips it; skipped with "reduce motion"), then a coloured welcome banner, the cut-out, icon tiles (number, name, title, issued, valid until) and a validity bar with the days left. Shared prop `hasMyRansId` decides if the menu item appears (Feature `id_passes` on).
* **Buttons**: every button in the module uses the WIMS `lg-btn tone` style (a colour per kind of action, icon in a bubble, soft shadow; solid for main actions). Row icons in the lists are coloured chips.
* Expired IDs cannot be printed or downloaded (`effective_status` is checked, also in the Print Centre and the batch runner); renew first.

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

## Limits and speed (measured with 1,000+ IDs)
* Pages answer in about 130 ms; the ID page carries 36 to 67 KB compressed; 200 passes are made in about 1.2 s (a stock of passes is logged once and sends no alert per pass).
* **Printed/PDF reports are limited to 600 rows** (`IdPassReports::PDF_MAX`): DomPDF needs memory for every row and a report of 1,000+ rows ends in a server error. The page warns, the server answers with a clear message, the CSV has no limit.
* Phone layouts checked at 375 px for every page: grids use `minmax(min(Npx, 100%), 1fr)` and `minmax(0, 1fr)` so nothing runs past the screen.

## Photo background (per category)

Each category has a **Photo background** colour (ID Settings > edit a category > Photo background; needs the template permission). Today: Staff white, Aluminium blue (#1e6fd9), Carpenter yellow orange (#ffa800); Visitor and Gate Pass are "Keep as taken".

- **How it is made.** The cut-out engine (U-2-Net, runs in the browser, nothing leaves the device) separates the person once per picture; the framing tool shows the person on the colour while you place the face. The card gets the framed picture on the colour (`card.jpg`) and the transparent cut-out (`cutout.png`, kept on the card). Used when issuing, changing the photograph and replacing an ID. The staff profile photograph goes through the same steps when the category has a colour.
- **Already on the colour: skipped.** The border of the picture (top and sides) is sampled; if 88% of it is within a small distance of the colour, nothing is cut. The same check runs on the server (`PhotoBackground::matches`).
- **Changing the colour later.** "Apply to the N IDs already issued" (same dialog) re-colours every open ID of the category: cards that kept a cut-out are re-coloured on the server instantly; photographs already on the colour are left alone; the rest are cut out one by one in the browser (progress bar, Stop button). The originals are never touched. Each change shows in the card history (Photograph changed) and the activity log.
- **Limits.** The person is found automatically: busy backgrounds or a person holding something may need a retake. If the engine fails the photograph is used as taken and the page says so. Server alone cannot cut out (no segmentation model in PHP), so an ID issued without a browser (API) keeps its photograph unless it already stands on the colour.
- **Tables.** Migration `2026_12_22_100000_idpass_photo_background` (category `photo_background`; card `photo_cutout`, `photo_bg`). Run it on the live and simulation databases; it also sets the three starting colours.

## Sample IDs

`php artisan id-passes:samples` makes 5 Staff IDs (staff members without an ID and without their own photograph), 5 Aluminium and 5 Carpenter worker IDs (with sample contacts), 8 Visitor Passes (3 with a visitor, 1 taken back) and 6 Gate Passes of people, in different states (active, soon to expire, expired, suspended, revoked, rejected), with drawn photographs on each category colour and a little print history. Nobody is notified while it runs. `php artisan id-passes:samples --remove` deletes them, their history and the sample contacts and gives the numbers back. Cards carry the note SAMPLE.

## Hover and tooltips

The card viewer lifts, glows in the colour of the ID and a band of light crosses it on hover; its buttons, the ID number and status chips in lists, the dashboard counts and the My ID badge have CSS tooltips (`lg-tip` + `data-tip`, no JavaScript). Tooltips and tilt are off on touch screens; motion is off with "reduce motion".

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

## Print Centre sheets, floating bar, category icons (2026-10-30)

- **Selected sheets** (right column, sticky): the real faces of the ticked IDs on the real sheet geometry (same arithmetic as `CardPdf::grid`, drawn in `Parts/SheetPreview.vue`). Ticking an ID flies it from its row onto the sheet, removing the tick flies it back; with more cards than one view holds there is a pager. Faces come from `GET id-passes/print-centre/faces` (max 40 ids). Nothing ticked: the sheet stays empty (the layout tiles keep their example IDs). Hover a card on the sheet to zoom it.
- **2. How** answers a tick at once (cards, sheets, per sheet are computed in the browser, the server count follows). Layout choices are tiles with a thumbnail of the real IDs.
- **Generate bar**: floating bar at the bottom (sticky on desktop, fixed on phones) with the count, the reason for printing again (when needed) and the Generate button.
- **Settings > Categories**: an ID icon in the colour of the category; hover or focus and the ID (front and back, sample holder, next number, current colours and photo background) grows out of it. Data: `GET id-passes/settings/samples`, reloaded when a category is saved.
- **Flicker**: hover effects that moved an element away from the pointer (`translateY` on hover) made it jump in and out of hover; they are now `scale` (module pages and Visitor Logs dashboard). The glare layer of the ID viewer no longer uses `mix-blend-mode`.
- **Handheld scanner** (`AutoComplete` prop `scan`, on Check-in, Check-out, Adjustment, Super Adjustment, Multi Transfer, External Stock): a quick burst of keys ending in Enter is one scan; the item is looked up once (`items.scan` with `full=1` returns the whole item), picked once, the field is free at once. Same code twice = quantity +1; unknown code = red flash and a message. Typing by hand and Enter still works and no longer picks a stale suggestion.
- **Scanner on Transfer** (item field of each row): on Enter the next empty row takes the cursor at once, then the item lands on its row with quantity 1 (a repeated code adds 1), so the field always waits for the next scan. Unknown code: its empty row is removed and a message shows. The live stock check of Transfer and Check-out runs on the new rows as before.
- **Background scanner** (`Core/scanListener.js`, installed once by `AdminLayout`; `POST scan/resolve`): a quick burst of keys ended by Enter/Tab, when no text field has the cursor, is a scan. A page with a scan field gets the code in that field (and the cursor); otherwise the server says what it is (WIMS QR code with its own permission check and log, item code/SKU, ID or pass number) and the record opens in a new tab. No timers, no polling, no request until a scan. Text boxes keep their own keys; the Enter of a scan never presses a focused button.
- The background scanner also opens a plain WIMS page address (a printed QR that carries one) and the reference number of any registered record (transfer, check-in, voucher, request, staff, asset, delivery note...): the table columns are found once and cached for an hour; a person only gets what they may open.

## Verification page (`/id-verify/{token}`, 2026-10-31)
Front/back flip card (front: live answer with status seal, live clock, validity meter that counts the days left, holder, number; back: the terms of the card, "Found this ID?", three quick checks), toggle Front/Back or tap the card, RANS contacts in the footer (call, email, website, map), faint animated architecture on the blue (skyline drawing itself, tower crane at work, compass). Suspended, revoked, replaced and rejected are red; expired and not-yet-valid are amber; valid is green. Public viewers still see only the first name and the initial of the last, no photo (a monogram instead).
