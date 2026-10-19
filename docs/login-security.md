# Sign-in restriction (2026-10-19)

All limits are soft-coded in `config/security.php` (`login`, `otp`; `.env` overrides `LOGIN_BLOCK`, `LOGIN_BLOCK_FAILURES`, `LOGIN_BLOCK_WINDOW`, `OTP_ESCALATION` ...). Code: `App\Services\Security\AccessGuard`, middleware `EnforceAddressRules`, tables `security_ip_rules` and `security_counters` (migration `2026_11_22_100000`). Test: `ver/sec_throttle.php` (52 checks).

## Wrong sign-ins (per IP address)
More than **10** wrong sign-ins (wrong password, or a wrong second-step code) from one address within **3 minutes** block that address for **10 minutes**; the next block of the same address is **20 minutes**, then **60**, then **24 hours** (the last one repeats). A block's memory is forgotten after a quiet day. While blocked, the sign-in, second-step code, resend-code, forgot-password and reset-password doors answer with the time left (also shown on the sign-in page); people already signed in are never thrown out. Blocks of 60 minutes or more are told to the Super Admins (bell, "Security alert").

## Blacklist / Whitelist (Login Logs tabs, Super Admin only)
- **Whitelist**: the address is never counted and never blocked (it also lifts a current automatic block). **Blacklist**: the address is always refused (optional end date, reason, a count of refused attempts). An address is in one list at a time. A Super Admin cannot blacklist the address he is using.
- Login Logs > a row has two buttons to blacklist or whitelist that attempt's address. The Blacklist tab also lists the automatic blocks (Lift block / Whitelist) and the accounts blocked from receiving sign-in codes (Lift block).
- Every change is written to the activity log (module LoginSecurity).

## Sign-in codes by Email / SMS (per account)
The first **3** codes go out as before (45 s pause); after that each new code needs a wait of **3, 5, 10, then 20 minutes**; once those are used the next request blocks the account from asking for codes for **24 hours** (only a Super Admin lifts it earlier). The sign-in page's "Resend in" countdown shows the real wait. A successful sign-in starts the count again (a block is not lifted by it). Enrolment codes on the Profile page keep the old rules.

## Works together with what was already there
The per-minute sign-in throttle (10/min per user and address, 20 per 10 min per user), the two-factor limiter (5/min), the flood limiter, the SQL guard, the sign-in question, the auto-logout and the Simulation environment keep working; this is an extra layer. Failures counted here come from the existing "failed login" event, so Login Logs show the same attempts. When the tables are missing (older database) the layer does nothing.

## Login Logs ask for the password again (2026-10-19)
Opening Login Logs (the log, its details, export / print / PDF, the Blacklist and Whitelist tabs) first shows a "Login Logs are protected" page that asks for the person's OWN password, even though they are signed in. The right password opens them for 10 minutes (`LOGIN_LOGS_UNLOCK_MINUTES`; the time is renewed while they work in these pages). A wrong password, or Cancel, sends the person back to the page they came from (never to a Login Logs page); five wrong passwords in 10 minutes pause the check. Not while logged in as another user (impersonation). Both outcomes are written to the activity log (module LoginSecurity). Code: `ConfirmLoginLogsAccess`, `LoginLogUnlockController`, page `LoginLog/Unlock.vue`; tests in `ver/sec_throttle.php`.
