# Simulation WIMS (dual database)

One WIMS codebase, two completely separate sets of databases. **Production** is the default and the real system. **Simulation** is a copy of the structure (never of the users) for demonstrations, training, scenario planning and "what if" work. Simulation can read chosen production records through a read-only door and copy them in; nothing it does ever reaches production.

Built 2026-10-19. **Not deployed.** OFF by default (`SIMULATION_ENABLED=false`): until it is switched on, the hidden login choice and every simulation address answer like an unknown address.

## 1. How the environment is chosen (and why it cannot be forged)

1. The login page looks and behaves exactly as before. The WIMS **logo clicked 3 times within 2.5 seconds** (both numbers in `config/simulation.php` / `.env`) asks the server (`POST /auth/ctx`, only from the page's own script, same site, with the page's security token) to remember "the next sign-in is for Simulation". The same three clicks again cancel it. Nothing is announced: no text, no toast. A thin animated **blue > indigo > violet > crimson ring** appears around the login card (a static ring with reduced-motion; it never blocks clicks).
2. The server answers with an **encrypted cookie** (`wims_env`, HttpOnly, SameSite=Lax). Only this server can write or read it. While you have not yet signed in it is "pending": it lasts 10 minutes, and opening or refreshing the login page forgets it.
3. `App\Providers\SimulationServiceProvider::register()` reads that cookie **before any other provider boots** and, if it says simulation (and simulation is on), points the two connections every module already uses (`mysql`, `accounts`) at the simulation databases, gives the simulation its **own session cookie** (`wims_sim_session`), **own cache folder**, **own permission-cache key**, **own file folders** (`<disk>/_simulation`), switches mail to the array mailer, and answers every outside web call with an empty reply. The whole application is therefore built for the right environment from the start; nothing built for the other one is ever reused.
4. The normal login (same form, same Fortify pipeline: honeypot, verification question, throttling, lockdown, 2FA) then runs against the simulation's own users. On success the session is marked `wims.env` and the cookie becomes a signed-in cookie.
5. On **every** request `EnsureEnvironmentIntegrity` checks the session marker against the environment, that a simulation session belongs to a **Category Authority** account that is still switched on, and that a production session belongs to an ordinary account. A mismatch signs the person out and is logged. A simulation cookie with no session behind it is dropped, so the person lands on the normal production login page.
6. If the simulation database cannot be reached the request ends with a plain **503**; the choice is kept, so retries fail the same way. It never becomes a production sign-in.
7. Switching WIMS always needs a full sign-out (`/auth/ctx` answers 404 to a signed-in person). Logout clears the cookie.

The glow, the cookie and the trigger are conveniences. **They are not a security boundary**: every request is re-checked on the server.

## 2. Who can sign in where

* Simulation users live **only** in the simulation database (`users` there). Nothing is copied from production users. A production password never works in the simulation and the reverse.
* Every simulation account carries the mark `category_authority` and is a **Category Authority** account. Only those can sign in to the simulation; they can **never** sign in to production (checked at sign-in and on every request, in both directions).
* Role **Category Authority Simulation**: every ability except the ones that run production itself (`config('simulation.never_permissions')`: backups, impersonation, WIMS Connect, licence, lockdown bypass, roles and permissions editing, simulation account management). It is not Super Admin. Super-Admin-only pages (commands, feature control) do not exist for it.
* Accounts are created and managed from **production** by Super Admins (or `manage-simulation-accounts`): **Users > Simulation Accounts** (create, full or custom access by module/ability, switch on/off, reset password, revoke, activity and sign-in logs). Switching off or revoking removes the person's sessions at once. Passwords are hashed straight into the simulation database; the page never shows one.
* The first account is created on the command line (below). `category.authority` was created on the local copy on 2026-10-19 with a password the owner chose and will change (use Simulation Accounts > Password).

## 3. Sign-in alerts (SMS, email, app alert)

Every successful simulation sign-in (and a burst of 3 refused ones from one address) is announced to the Super Admins through the normal workflow notifications: **Users > Simulation Alerts** (who, which channels, which words; default Super Admin by bell, email and SMS). It is sent from the **production** side after the simulation request has finished, written to the production activity log, and never inside the simulation.

**Testing limit:** `SIMULATION_ALERT_ONLY=admin` (comma separated usernames) restricts the alert to those people (here DrHajas, username `admin`); a name that does not exist tells nobody. Remove the line to tell every Super Admin.

## 4. Setup (server side)

```
# .env
SIMULATION_ENABLED=true
SIM_DB_DATABASE=wims_simulation              # its own databases ...
SIM_DB_ACCOUNTS_DATABASE=wims_accounts_simulation
SIM_DB_USERNAME=...                          # ... and its own login: rights on those two databases ONLY
SIM_DB_PASSWORD=...
SIM_PROD_RO_USERNAME=...                     # a login with SELECT only on production (used by the Import Centre; also forced READ ONLY per session)
SIM_PROD_RO_PASSWORD=...
SIMULATION_ALERT_ONLY=admin                  # testing only
```

```
php artisan migrate --force                  # adds the simulation abilities in production
php artisan simulation:install --admin-user=root [--sim-user=NAME --sim-name="Full name"]
#   creates both databases (admin login used for this run only), copies the STRUCTURE of production (tables, keys, indexes),
#   a few lookup tables (units, categories, ...), the settings that are safe (appearance, numbering: never a key or gateway),
#   the simulation-only tables, the role; the password is asked for or read from SIM_ADMIN_PASSWORD (never printed).
#   --allow-weak-password accepts a password that does not meet the policy (owner's own choice, to be changed).
php artisan simulation:status
php artisan simulation:sync                  # after every deployment: new abilities and simulation-only tables
WIMS_ENVIRONMENT=simulation php artisan migrate --force   # apply NEW production migrations to the simulation databases
```

On cPanel hosting the app cannot create databases: create the two databases and their user in cPanel, then run the install with `--admin-user` set to that user (it only needs table rights). The installer refuses to run if a simulation database equals a production one.

Deployments never reset the simulation database. Back up and restore the two simulation databases separately from production (`mysqldump wims_simulation`, `wims_accounts_simulation`).

**Rollback:** set `SIMULATION_ENABLED=false` (everything answers like an unknown address; production is untouched) and, if wanted, drop the two simulation databases. Production tables are never altered by any of this except the new permission rows and routes.

## 5. What is isolated

| Thing | How |
|---|---|
| Data (both schemas) | the two connections point at other databases for the whole request |
| Sessions | own cookie name and own `sessions` table (in the simulation database) |
| Cache, rate limits, permission cache | own cache folder (`storage/framework/cache/data-simulation`) and permission key |
| Files and uploads | `<disk root>/_simulation` and `/…/_simulation` links; imported documents are copied there, never shared |
| Mail | array mailer: nothing is sent |
| SMS / WhatsApp / Meta | every gateway is the log-only mock |
| Outside web calls (weather, AI, …) | `Http::fake()`: answered empty, never leave |
| Queued jobs | each job carries its environment; a worker moves to it before the job runs; no marker = production |
| Console and scheduler | production only; `WIMS_ENVIRONMENT=simulation php artisan …` runs on the simulation |
| Licence, prayer lock | do not apply inside the simulation (they belong to production) |
| Face ID, backups, commands, impersonation | not available in the simulation |
| Exports | marked "SIMULATION - NOT OFFICIAL" (below) |

## 6. Import Centre (Simulation > Import Centre)

Read-only production access through `mysql_production_ro` / `accounts_production_ro` (SELECT login, `SET SESSION TRANSACTION READ ONLY` on every connection) and only tables named in `SimulationCatalog`: contacts, projects/budgets, subcontract agreements (+ schedule groups, items, opening balance/payment history, variations, requests), payment vouchers (+ lines), payment certificates (+ items), labour lists (+ people), transport records, staff, items, accounts/expenses (Accounts schema). Search, filter, page, select, **preview**, then **review what will be imported**: required dependencies (brought in automatically), related records that are optional (tick to include), parts that belong to the record (tick/untick), duplicates (already imported: reused). Options: mask personal details (default on) and copy attached documents (default off).

Records are copied as **independent** simulation records: every link is remapped to the simulation's own ids (declared foreign keys and naming-convention links are followed; a link that cannot be carried over becomes empty and can never point at production). People links become the importer. The whole import is one transaction on both schemas; a record imported before is recognised (retry is safe). Unique values that already exist get a suffix. Batches are listed with their result and can be run again.

Spreadsheets (Excel/CSV) of planned or fictional records (contacts, projects, subcontract agreements, items) are checked row by row first and saved only if every row is fine; they are marked synthetic.

**Provenance** (Simulation > Imported And Synthetic Records): each imported record keeps its production id, batch, importer, time and a baseline copy. **Imported** = unchanged, **Modified copy** = edited since (found by comparing with the baseline, however it was edited), **Synthetic** = made in the simulation. A record can be compared with its baseline and put back as imported (password + typed word).

## 7. Scenarios and checkpoints (Simulation > Scenarios And Checkpoints)

A scenario is a named state; one is open at a time. A checkpoint is a full copy of the open scenario's records kept as `sc<N>_…` tables inside the simulation databases (no extra database needed). Create a scenario (copy of what is open or of another), open another (the one you leave is saved first), save checkpoints, set the baseline, restore a checkpoint or reset to the baseline (password + typed word; the present state is saved first), compare two states (counts and money headline figures), archive, delete (drops its copies). People, roles, sessions, logs and the scenario list itself are never part of a copy. Nothing here can reach production.

## 8. Documents carry the disclaimer

Signed-in simulation pages show a slim **SIMULATION** strip (scenario name) on every page, from the server. Print pages and PDFs get a banner top and bottom and a faint SIMULATION watermark (with scenario and time). Excel files get a note row under the data, the document title and the file name `SIMULATION_…`; CSV a note row; every download is renamed `SIMULATION_…`. One place does it (`MarkSimulationExports`, `SimulationPdf`).

## 9. Tests (all on dedicated test databases; production = the fictional demo database)

Scripts in the session scratchpad `ver/`: `sim_login.php` 41, `sim_http.php` 60, `sim_env.php` 36, `sim_marks.php` 14, `sim_import.php` 85 checks, all passing on 2026-10-19; plus the existing suites (Subcontracts 11 suites, `acct_core`, `acct_http`, `sa_two_approvals`) all green after the change. They cover: default production, the hidden choice (and that it needs the page's own request), cancel, expiry, refresh; production credentials never work in the simulation and the reverse; wrong password never falls back; session bound to its environment, forged and swapped cookies, logout, expired session, deactivation and password change ending live sessions; database failure answers 503 without fallback; Category Authority marking both ways; account management and its abilities; import (links remapped, 798 links checked with none broken, production byte-for-byte unchanged, duplicates, masking, optional/child options, files), provenance states and compare/restore, spreadsheet import and rejection, scenarios and checkpoints; export marking; the alert audience (bell and SMS captured; one real SMS and email were sent to DrHajas only, on 2026-10-19).

## 10. Known limits (honest list)

* The environment is decided once at the start of a request; there is no switching inside a request or a session.
* The state chips (imported / modified / synthetic) are on the Provenance page, not on every module's own screen.
* A link the importer cannot recognise (no foreign key, no naming match, not listed in `SimulationCatalog::links()`) is left empty rather than guessed. New modules should be added to the catalog.
* Checkpoints copy tables: very large simulations make saving slower; each checkpoint adds ~250 tables to the simulation database.
* Excel notes are a row under the data (plus title and file name), not a page header; a print page that has none of `@page`, `window.print` or a letterhead is not recognised as a document.
* Simulation accounts must not enable email/SMS two-factor (nothing can be sent there). The first password of `category.authority` is weak by the owner's choice; change it first.
* The AI assistant and search work on simulation data (they use the same connections) and its outside calls are answered empty; it was not exercised end to end beyond that.
* Scheduled tasks and queue workers serve production; the simulation has no schedule of its own.

## One database for the simulation (2026-10-19)
`SIM_DB_ACCOUNTS_DATABASE` is optional: when it is not set the simulation's accounts tables (`acct_*`, 13 tables, no name clashes) live in `SIM_DB_DATABASE`, exactly like production when `DB_ACCOUNTS_DATABASE` is not set. `simulation:install` accepts one database for both when production also uses one. Shipping dump: `database/data/simulation/wims_simulation_combined.sql.gz` (main + accounts tables).
