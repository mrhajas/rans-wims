# Super Adjustment: two Super Admin approvals (2026-10-18)

## The rule
A setting on the Super Adjustments page ("Change rule", Super Admin only, confirmed with the Super Admin's own password) decides how many **different** Super Admins must approve before the stock changes: **two (default)** or **one**. Stored in `settings` (`super_adjustment_approvals`), read by `SuperAdjustment::requiredApprovals()`. Two needs at least two active Super Admins. Every change is in the activity log.

## Two approvals (default)
1. Anyone (a Super Admin too) saves a count: it is a Draft. A Super Admin's own count is no longer approved on the spot.
2. A Super Admin presses Approve and types **their password** (wrong password: refused, logged, counted by the security alert). Phase 1 is recorded (person, role, time, address). Status becomes **Awaiting Phase 2**. The stock does not change.
3. The other active Super Admins are told by **SMS, email and the app bell** (Alerts page, "Adjustments Alerts", event "Super Adjustment Needs Second Approval": on by default for the role Super Admin, wording editable). The first approver is not told about their own action.
4. A **different** Super Admin presses Approve with their password: phase 2. Only now the Addition/Subtraction Adjustments are created and the stock changes. The record keeps both approvals.
- The same Super Admin can never give both phases (checked under a lock; tested with real parallel requests: pressing Approve twice at the same instant leaves exactly one approval). Approving while logged in AS another user (impersonation) is refused.
- Reject (Draft or Awaiting) voids the approvals; editing or putting the record back to Draft voids them too. Voided approvals stay as history, never deleted. The status picker cannot force "Approved" under the two-approval rule.
- Only Super Admins can approve under the two-approval rule (with one approval the earlier permission `approve-super-adjustments` still works).

## One approval
As before: a Super Admin's own count is approved on submission; others wait for one approval, which now also needs the approver's password.

## Where it shows
Record page: an "Approvals" card with Phase 1 / Phase 2 (who, role, time, address, or "Waiting"), the voided ones in a fold-out. List: the rule card, an "Awaiting 2nd approval" tile and chip. Print/PDF: "Approval phases" table. Signatories: new action `first_approved` (phase 1) next to `approved` (final). Tables: `super_adjustment_approvals`; migration `2026_11_14_100000`. Tests: `ver/sa_two_approvals.php` (39), `ver/sa_race.sh` (real parallel).

## SuperAdjustment ByPass (2026-10-19)
Profile page, section **SuperAdjustment ByPass**, visible only to Dr Hajas (`LicenceService::isManager`, really signed in, never while impersonating). A switch plus "Who approves phase 2?" (a searchable list of the other active Super Admins), saved with his own password. Settings (soft-coded in `settings`): `super_adjustment_bypass_active` (1/0) and `super_adjustment_bypass_approver` (user id).

While it is active and the rule is two approvals, when Dr Hajas approves a Super Adjustment that has no approval yet (phase 1, with his password as usual) WIMS records phase 2 at once for the chosen Super Admin (role, time, note "Approved by WIMS through the SuperAdjustment ByPass of Dr Hajas", no address), creates the Adjustments and updates the stock, and tells the submitter; the "needs second approval" message is not sent. The signature rule is checked against the Super Admin who completes the record. If he gives phase 2 after someone else's phase 1 it is the normal two approvals. If the chosen approver is no longer an active Super Admin (or is Dr Hajas himself) the ByPass is ignored and phase 2 stays open; nothing is approved silently.

Approvals already given are never changed: only Super Adjustments approved after a change use the new choice. Every change is written to the activity log. Migration `2026_11_21_300000` switches it ON with Director Asha (`mrshajas`) when the settings do not exist yet (and she is an active Super Admin); it never overwrites a later choice. Earlier approved records show Phase 1 / Phase 2 (migration `2026_11_20_200000`; SADJ-0001: Eng Ali then Director Asha). Test: `ver/sa_bypass.php` (20 checks) and `ver/sa_two_approvals.php` (39).
