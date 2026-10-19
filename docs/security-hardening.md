# Security hardening (2026-10-18)

## SQL injection
- **Before:** not exploitable. Every value a person types reaches MySQL as a bound parameter (Eloquent / query builder), and every place that builds raw SQL uses a column or direction taken from a fixed list, never from the request. Checked by reading all ~60 raw-SQL spots and by `ver/sqli_fuzz.php` (2,605 requests: injection strings in every common parameter of every one of 521 list/report/export pages, with the guard on and off): no payload text ever reached a SQL statement, nothing slowed down (no time based injection).
- **Weak spots found and closed** (not injections, but crashes on odd input): a login user name sent as an array or 100,000 characters long gave a server error; an unreadable date in a report URL gave a server error; Case Tracking Analytics crashed when filtered by project or date (ambiguous column, fixed in `CaseTicket::scopeFilter`).
- **Second line of defence:** `App\Http\Middleware\SqlInjectionGuard` (config `security.sqli_guard` = block | log | off, env `SQLI_GUARD`).
  - Sign-in pages (login, face login, forgot/reset password, two-factor): only plain values: user name = letters, digits and `. _ @ + -` (max 100), e-mail the usual characters, codes letters/digits/dashes, no arrays, no very long text. Anything else answers like a wrong password, before the database is touched.
  - Every other page: text is scanned for the unmistakable shape of an attack (UNION SELECT, `; DROP TABLE`, `' OR '1'='1`, `SLEEP(5)`, `information_schema`, `ORDER BY 3--`, ...). Semicolons, colons, apostrophes and times such as 08:30 are NOT refused: they are normal text. Tested against all 176,856 text values already in the live copy (0 refused) and 14 realistic sentences (0 refused), and 14 attack strings (all refused). Passwords, tokens, signatures are never scanned.
  - A refusal answers 422 (page) or goes back with a message (form), is written to the log and the activity log ("Security"), and counts towards the security alert below.

## Rate limiting and other protections
- `ThrottleRequestFloods` (numbers in `config/security.php`, `.env` RL_*): 900 requests/min per signed-in person, 240/min per guest address, 240 changes/min, sign-in pages 30/min and 200/hour per address whatever user names are tried (on top of the existing login limiter: 10/min per user+address and 20/10 min per user across all addresses, the arithmetic challenge after 3 failures, the honeypot field, the two-factor and reset-password limiters), and a signed-in person who sends a password (confirming an action, changing a password) gets 12 tries per 10 minutes. Refused requests answer 429 with Retry-After.
- `SecurityHeaders`: X-Frame-Options, nosniff, referrer policy, permissions policy (camera and microphone only for WIMS itself), frame-ancestors/base-uri/object-src/form-action content policy, HSTS on HTTPS, no X-Powered-By. `public/.htaccess` adds the same for static files and denies dot files, dumps, backups, logs; the root `.htaccess` also denies composer/package files.
- Passwords: one rule (`App\Support\PasswordPolicy`): 8+ characters with upper case, lower case and a number, for new users, password change, reset and profile; new hashes use bcrypt cost 12.
- A production server forces debug, the debug bar and Ignition's runnable solutions off whatever the `.env` says.
- Security alert: when one address is refused 8 times in 10 minutes the administrators get a notification (bell) naming the address (`SecurityAlert`).

## Where an administrator sees it
- System Logs (`/activity`), Module "Users & Security": "Suspicious input refused" with the rule, the field, the first 160 characters, the page and the address.
- Login Logs (`/login-logs`): every sign-in attempt, successful or failed, with address, device and role (already existed).
- Notifications (bell) for Admin and Super Admin: "Security alert".
- `storage/logs/laravel.log`: warning lines "Security: suspicious input ..." (technical).
- Tests: `ver/sqli_fuzz.php`, `ver/sqli_login.php` (17), `ver/sqli_falsepos.php`, `ver/sec_test.php` (14), `ver/sec_alert.php` (2).
