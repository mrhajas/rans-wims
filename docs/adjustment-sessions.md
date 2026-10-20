# Adjustment Session (Super Adjustment only)

Built 2026-10-21. For a physical count of one Project / Warehouse (godown) that takes several days and several Super Adjustments. Normal Adjustments are not touched.

## How it works
1. **Start** (`start-adjustment-sessions`): Super Adjustments > Adjustment Sessions > Start. One open session per Project / Warehouse (unique `open_warehouse_id`, so it holds under a race). Reference `ASN-0001`.
2. While it is **open**, every Super Adjustment made for that warehouse is linked to it (`super_adjustments.adjustment_session_id`, set in `SuperAdjustmentController::persist`; an older count is linked on edit only if it was made after the session started). On the form the "items not in this count" question is switched off for that warehouse (`uncountedItems` answers `[]`) and a banner says the count belongs to the session. With no open session the question works exactly as before.
3. An item is **counted** once it is on a Draft, Awaiting or Approved Super Adjustment of the session (a Rejected one does not count). **Not counted** = items with stock in the warehouse on no such document, live on the session page, with a "moved since start" flag when the stock row changed after the session started.
4. **End** (`end-adjustment-sessions`): refused while a document of the session waits for approval. The dialog lists the uncounted items with tick boxes (all ticked except the ones that moved). Ticked items are cleared by ONE normal Super Adjustment (counted at zero, `auto_cleared`), created through `SuperAdjustmentController::submitCount`, so the usual one / two Super Admin approvals still apply (clearing needs `create-super-adjustments` too). Unticked items stay as they are. Ending without ticking anything is allowed. The result (counted / cleared / kept) is kept in `adjustment_sessions.snapshot`.

## Where
- Pages: `resources/js/Pages/AdjustmentSession/{Index,Show}.vue`; banner in `SuperAdjustment/Form.vue`; session chip and filter (`?session_id=`) on the Super Adjustments list and page.
- Code: `AdjustmentSessionController`, `App\Services\Adjustments\AdjustmentSessionService`, model `AdjustmentSession`, print `resources/views/adjustment-sessions/print.blade.php` (print + PDF, logged by the print/download logger).
- Registered: permissions (`read/start/end/print-adjustment-sessions`, Super Admin by default, migration `2026_12_03_100000`), Feature Control `adjustment_sessions` (depends on `super_adjustments`), menu (Adjustments), launcher search, QR kind `adjustment_session`, Smart Brief record link, Adjustments Alerts events `session_started` / `session_ended`.
- Tests: `ver/adj_session_test.php` (57 checks).

## Samples and checks (2026-10-21)
- `php artisan adjustments:session-samples` adds two review samples to the LOCAL database (an OPEN session with an approved and a waiting count, and an ENDED session with two approved counts and the clearing count waiting for approval); every one says SAMPLE; counts equal the stock, so no stock moves and nothing is sent. `--remove` takes them away.
- A Super Adjustment page and print show the session it belongs to: reference, name, who started it, from which date to which date (or "now, still open"), a click opens the session (needs `read-adjustment-sessions`).
- Bug found and fixed on the way: MySQL gave `started_at` (the first NOT NULL timestamp) an automatic ON UPDATE CURRENT_TIMESTAMP, so ending a session would have moved its start date to "now". The column is now a nullable plain timestamp (migrations `2026_12_03_100000` and `2026_12_03_100100`); `adj_session_test` checks it.
