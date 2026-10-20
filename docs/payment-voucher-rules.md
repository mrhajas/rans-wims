# Payment Voucher rules (added 2026-10-21)

Fixes for the open Payment Voucher decisions. Tests: `ver/pv_rules.php` (54 checks), the older `pv_*` suites run with separation switched OFF in their set-up (they test the workflow itself).

## Project access (IDOR)
A person assigned to projects (`user_warehouse`) opens and acts only on the vouchers (and batches) of those projects, the company-wide ones (no project) and the ones they prepared. Everybody who is not limited to projects (Super Admin, `view_all`, an Admin with no assignment, nobody assigned) sees all, as in the rest of WIMS.
- `PaymentVoucher::projectIdsFor / scopeVisibleTo / visibleToUser`, `PaymentVoucherBatch::scopeVisibleTo`.
- Route middleware `pv.scope` (`EnsurePaymentVoucherInScope`) on every route with a voucher / batch id: show, print, pdf, edit, update, delete, submit, approve, authorise, reject, cancel, record payment, files.
- Lists, cards, export, print / PDF lists, the fund picker, the transport fuel report, the batch list, the bulk approve / authorise / mark paid ids are all filtered. The project dropdowns only list the person's projects; raising a voucher or batch for another project is refused.

## Separation of duties (on by default)
Nobody takes two steps of the same voucher, EXCEPT Super Admins, who are exempt (owner decision 2026-10-21: a Super Admin may take every step themselves): the preparer cannot approve it; the approver and the preparer cannot authorise it; the authoriser cannot record its payment. `App\Support\VoucherSeparation`, setting `pv_separation_of_duties`. A Super Admin switches it on / off for everybody else (with their password, logged) on the Payment Vouchers page.
- Bulk Approve & Authorise: Submitted vouchers are only approved by that person (another person authorises them); Approved ones by someone else are authorised; the answer says how many were left.
- The pages hide the buttons the server would refuse and say why.
- Administration, Travels and subcontract vouchers follow the same rule (the person who started the voucher in their module cannot approve it).

## Cancel
A cancellation needs a reason, kept with who and when (`cancelled_by_user_id`, `cancelled_at`, `cancel_reason`, migration `2026_12_04_100000`) and in the activity log. Besides Draft / Submitted / Approved, an AUTHORISED voucher that is not paid can be cancelled by someone with the authorise permission (wrong payee or amount found before payment); linked records are released by the same listener as before. A paid voucher is never cancelled.

## Files
A wrongly attached file can be removed from a Draft or Rejected voucher (`DELETE payment-vouchers/{id}/attachments/{file}`, needs the update permission, logged). A voucher on its way keeps its files.

## Batches
Bulk Vouchers stop the same payee for the same amount twice in one batch, or a row equal to a recent voucher (same payee, amount, date, last 3 days), until the person confirms ("Continue anyway?"), like a single voucher.

## Still open
Ownership of vouchers by the person who prepared them for the "mine" view is not restricted further; reports in other modules that sum vouchers are not project-scoped.
