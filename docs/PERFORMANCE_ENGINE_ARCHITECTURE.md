# WIMS Performance Engine: architecture assessment and rollout plan

Status: plan for approval. Nothing of the engine has been implemented yet.
Prepared 2026-11-02 from the code at commit `88239f6df` (backup taken first, see section 10).

## 1. What WIMS is today (measured from the code, not assumed)

| Area | Fact |
|---|---|
| Backend | Laravel 11, PHP 8.3 (live host: ea-php82), Sanctum, spatie/permission, spatie/activitylog, DomPDF |
| Frontend | Inertia + Vue 3, Vite 5, Tailwind, vue-i18n, vite-plugin-pwa (generateSW), onnxruntime-web (photos) |
| Database | MySQL/MariaDB, 328 tables, 465 migrations, 273 models, 148 controllers, about 1,680 route registrations. A second schema family (`accounts`, simulation DBs) shares the connection style |
| Hosting | cPanel shared hosting, no SSH, deploys by Git copy. Cron only: no persistent workers, no Redis |
| Drivers | `CACHE_DRIVER=file`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=sync` (jobs run inside the request), no Redis in use |
| Modules | 196 feature keys in Global Feature Control, 11 groups: Core 4, Warehouse & Inventory 15, Finance 19, Administration 81, Projects 41, Tools 5, Reports & Intelligence 6, Security 1, Project Certificates 1, WIMS Connect 5, Communication 18 |
| Access control | Roles and permissions (spatie), `FeatureGate` middleware enforces Global Feature Control server-side (Super Admin passes), `system.lockdown`, device limit, idle timeout, licence |
| Audit | spatie activity log plus module logs, Delivery/Download/Print logs, System Logs page |
| Backups | Existing Backup feature (`backup`) plus manual dumps; the backup taken for this work is in `wims-backups/WIMS_PrePerformanceEngine_20261102` |

### What already exists and must be reused, not rebuilt

1. **Offline Read Mode.** `OfflineSyncController` (`/offline/sync`), `resources/js/Core/offline{Db,Sync,DataSource,Status}.js`, IndexedDB database `wims-offline` with 9 stores (items, categories, warehouses, stock, checkins, checkouts, transfers, adjustments, material-requests). Read only. A mutation sent while the client believes it is offline is refused by `RejectOfflineMutations`. The cache is cleared on logout and on a different user signing in. Its Global Feature Control key is `read_mode`.
2. **Service worker.** vite-plugin-pwa caches hashed build files and the route table (`/ziggy.js`) only. Navigations are never served from the worker (deliberate: it once leaked one user's pages to the next).
3. **Performance tooling.** Settings > Performance & Data (`performance_data`: slow-request log, activity archive, queue mode, housekeeping), Settings > Optimize (`optimizer`: tasks, speed probe, metrics), `RecordSlowRequest` middleware, 16 `Cache::remember` uses (Ziggy, Inertia shared data, Archive stats, weather, QR/scan resolution, chat analytics).
4. **Network signal.** `Core/networkSignal.js` (now a static ping file).
5. **Idempotency, partly.** Accounts transactions/transfers and the WIMS Connect gateway only. Payment Vouchers and every other module have none.

### Honest gaps against the brief

| Brief requirement | Today |
|---|---|
| One central engine | Offline read, slow log, optimizer, Ziggy cache are separate pieces with no shared registry or switch |
| Master switch plus granular switches plus per-module policy | Only `read_mode` (offline read) is a switch. No master switch, no per-module policy |
| Incremental sync with server-ordered cursor | Uses `updated_at > since` plus `skip/take` paging. Offset paging can skip or repeat rows when data changes between pages. Hard deletes and permission changes are not signalled |
| Version checks / optimistic concurrency | None found on business records (no version column, no If-Match). Last write wins on most forms |
| Idempotency keys | Only in two places (above) |
| Offline coverage | 9 inventory resources only. Staff, Contacts, Projects, Subcontracts, Site Diary, Visitor Logs and the rest are online only |
| Cache invalidation | Browser cache refreshes by `since`; server `Cache::remember` entries expire by time, not by change |
| Background jobs | Queue is `sync`; there is no job table, retry policy or stuck-job monitor |
| Dashboard | No engine dashboard; the slow log and optimizer are separate pages |

Consequence: the engine is mostly an **evolution** of what exists, plus a real **integrity layer** (idempotency, versions) that does not exist yet and is the part that protects money.

## 2. Architecture

### 2.1 Principle: an optional layer, never a dependency

Every integration point has two paths. The normal path (controller, query, Inertia page) is untouched. The engine path is wrapped in one gate:

```
Engine::active('local_cache', 'staff')   // false when master OFF, feature OFF, module OFF, circuit open, or any internal error
```

Any exception inside an engine feature is caught, counted, logged, and the request continues on the normal path (fail open for performance, never for security). Authorization, permissions and Global Feature Control are always evaluated by the existing code first.

### 2.2 Server components (all under `app/Support/PerformanceEngine/`, namespaced, no module edits except thin hooks)

| Component | Job |
|---|---|
| `Engine` | The single gate: master switch, feature switches, module policies, circuit breaker (auto-open after N internal errors in M minutes, auto-close after a cool-down). Decisions cached for the request |
| `Registry` | Per-module policy table (section 4), defaults in code, overrides in `performance_engine_modules` |
| `ChangeFeed` | Server-ordered change log: table `performance_changes` (auto-increment `id` is the cursor; module, record_id, op created/updated/deleted/permission, version, scope key, created_at). Written by a model trait/observer on registered modules only. Solves offset paging, hard deletes and permission-scope changes |
| `Invalidator` | Dependency-aware invalidation (record to its views, project to its children, permission change to that user). Driven by the same observer events, so it also sees changes from other users, jobs and imports |
| `Integrity` | Idempotency middleware (header `X-Idempotency-Key`, table `idempotency_keys`: key, user, route, request hash, response snapshot, status) and a version precondition helper (`If-Match` on `updated_at`/version) |
| `Jobs` | Table-backed job record (`performance_jobs`: id, kind, status, attempts, max_attempts, locked_until, last_error), a cron-driven runner (works on cPanel), duplicate-job guard, stuck-job sweeper |
| `Monitor` | Real measurements only: request time buckets per module, cache hit/miss counters, sync outcomes, errors. Stored in `performance_metrics` (hourly rollup) |
| `Controls` | Reads/writes Global Feature Control keys, audit log entries, notifications |

### 2.3 Browser components (`resources/js/Core/engine/`)

| Component | Job |
|---|---|
| `engineState` | Reads the switches from the Inertia shared props (so OFF takes effect on the next page load) and from a lightweight `/engine/state` poll (so OFF takes effect on the next sync tick). OFF means: stop reads from cache, stop syncing, keep the stored data untouched |
| `store` | IndexedDB wrapper generalising the current `wims-offline` DB (same DB, additive stores, per-user namespace, quota handling, corruption detection that drops the store and rebuilds from the server) |
| `syncClient` | One cursor per module and per user, applies pages inside one IndexedDB transaction, advances the cursor only after the whole batch applied, retries with back-off, reports failures to the server |
| `readThrough` | The one function a page calls to read: try cache if allowed, else server; never required |
| `drafts` (stage 7 only) | Local form preparation and saved drafts; never a transaction queue |

### 2.4 Switches (Global Feature Control)

New keys in `SystemFeatureRegistry` (group "Performance Engine", Super Admin only, all logged):
`perf_engine` (master), `perf_local_cache`, `perf_incremental_sync`, `perf_offline_read` (the existing `read_mode` is kept as an alias so nothing breaks), `perf_background_refresh`, `perf_static_assets`, `perf_dashboard_cache`, `perf_search_cache`, `perf_api_cache`, `perf_bg_reports`, `perf_bg_pdf`, `perf_bg_files`, `perf_local_prep`, `perf_offline_queue` (reserved, shipped OFF and not wired), `perf_monitoring`.
The master overrides all. Direct URLs and API calls of a disabled feature return 403 through the existing `FeatureGate`.

**Switching OFF** sets the master flag; the next request is on the normal path; the service worker data cache and the IndexedDB reads stop on the next state check; the cache tables and IndexedDB data are kept (a later ON resumes with a normal refresh); queued drafts are kept and shown; running jobs finish their current transaction and then stop; nothing is deleted.

## 3. Data integrity layer (net new, built before any caching of business data)

1. **Idempotency.** Middleware on mutating routes of critical modules (Payment Vouchers, Salary, Contracts/Subcontracts, Inventory movements, approvals). Client forms send a key generated when the form opens. Same key + same payload returns the stored result; same key + different payload is refused. Unique constraint on (user, key). Rolled out module by module, starting with Payment Vouchers.
2. **Versions.** Version precondition on update: the form carries the record's `updated_at` (or a `version` integer where `updated_at` is not precise enough); the server compares under a row lock and rejects a stale write with a clear "this record changed" response and a refresh/compare option. First targets: Payment Vouchers, Contracts, Staff, Contacts, Projects.
3. **Transactions.** Critical writes already run in `DB::transaction` in many controllers; the audit step of the rollout lists those that do not and fixes only those, with tests.
4. **Money never from cache.** Payment, balance, approval and stock-deduction checks keep reading the database inside the commit transaction. The engine has no code path that feeds a cached figure into those checks (enforced by a test that greps the cache layer's callers).

## 4. Module policy table (initial, to be confirmed after the audit)

Risk classes: **A** reference data (read-cache OK), **B** restricted (cache only what the user may see, short expiry, cleared on logout), **C** online only (no business data in the cache).

| Module (GFC keys) | Class | Offline read | Local prep | Offline write |
|---|---|---|---|---|
| Items, Categories, Units, Warehouses, Contacts | A | Yes | Yes | No |
| Stock, Check-in/out, Transfers, Adjustments, Material Requests | A/B (exists today) | Yes (exists) | Yes | No |
| Staff (`staffs`) | B | Yes, without salary/ID/phone numbers beyond what the list shows | Yes | No |
| Projects, Subcontractors, Contracts (`subcontract_*`) | B | Yes (headers and statuses; no amounts above the user's rights) | Yes | No |
| Site Diary, Site Meetings, Case Tracking, Visitor Logs, Daily Site Reports | B | Yes | Yes | Controlled draft only (stage 7, after review) |
| Payment Vouchers, Salary, Loans/Advances, Accounts Tracking, Fund Allocation, Administration payments, Travel payments | C | No (list headers only if the owner approves) | Yes (blank form helpers only) | No |
| Archive, Signatories, ID Passes, QR | C | No | No | No |
| WIMSChat | B | Recent authorised messages | Local draft text | No |
| Smart Brief, Executive Report, Reports | B | Last generated, per user | No | No |
| ARIS (`ai_assistant`) | C | No | No | No |
| Notifications | B | Recent authorised | No | No |
| Everything else (WIMS Connect, settings, licence, users, logs) | C | No | No | No |

## 5. Rollout stages (each reversible, each gated by its tests)

**Stage 0: foundation (no behaviour change).** Switches in GFC, `Engine` gate with circuit breaker, `Registry`, audit logging, engine dashboard shell, `perf_*` tables. Everything defaults OFF.

**Stage 1: observation.** `Monitor` records real timings per module/route/query, cache hit counters (the existing ones), sync outcomes. Baseline report produced before anything else changes. Real numbers only.

**Stage 2: safe static caching and frontend savings.** Fold the existing Workbox runtime caching under the `perf_static_assets` switch; compression/cache headers check; lazy loading audit; debounced search review. Slow-query audit and index additions (additive migrations only) from the slow log.

**Stage 3: reference-data cache and invalidation.** Server-side `Cache::remember` for reference lists and dashboard statistics behind the gate, with `Invalidator` (change-event driven, not time driven). Same for the browser cache of Items, Contacts, Projects, Staff lists.

**Stage 4: incremental sync on `ChangeFeed`.** Replace `since + skip/take` with the server cursor for the existing 9 resources first (parity test against the current sync), then add Staff, Contacts, Projects, Subcontracts. Hard-delete and permission-change events included.

**Stage 5: offline read for the approved class A/B modules.** Extend the existing read mode shell, per the table above.

**Stage 6: background processing.** `performance_jobs` plus a cron runner; move PDFs/reports/exports/large imports (the long ones identified in stage 1) off the request. cPanel cron every minute is the worker.

**Stage 7: controlled local preparation and drafts.** Local form preparation and drafts for Site Diary, Visitor Logs; no transaction queue. A queue stays a reserved, OFF switch until a separate written review.

The integrity layer (section 3) is built alongside stages 0 to 2 because stages 3 to 5 depend on it. A stage does not start until the previous one has passed its checks.

## 6. Security rules (checked in tests)

Server-side authorisation on every sync/state endpoint; sync scope computed with the same helpers the list pages use (`warehouse_scope_ids()`, policies); cache keys include user id and account id; cache cleared on logout, permission change, deactivation and account switch (a `permission` event in the change feed forces a re-sync of that user); no sensitive field in a cached row unless the module policy says so; rate limits on sync endpoints; every switch change, fallback and emergency shutdown written to System Logs; Super Admin notified of repeated failures only (no routine noise).

## 7. Emergency shutdown

Master OFF in Global Feature Control (or `php artisan engine:off` for a no-UI case, and a file flag `storage/framework/engine.off` that the gate reads first, so it works even if the database or cache is the thing that is failing). Effect on the next request. Committed transactions are never interrupted; pending drafts and jobs are kept and listed. Automatic fallback: circuit breaker opens after repeated internal errors and the request goes the normal way.

## 8. Test plan

Master switch on/off with every module reachable; each cache failure mode (IndexedDB unavailable, cleared, corrupted, expired, file cache unwritable, service worker missing); duplicate submit, simultaneous submit, stale edit, rollback, network loss mid-submit and mid-sync, logout, permission change, user deactivation; parity test of the new sync against the old one; every GFC module touched at least by a smoke request with the engine ON and OFF; measured before/after on the stage 1 baseline. Results are recorded as measured, not estimated.

## 9. Limits and decisions needed from the owner

1. Live hosting is cPanel: no Redis, no always-on workers. The plan uses the file/database cache and a cron runner. Redis and real workers are a separate VPS step and are documented, not assumed.
2. Offline writes stay off. The switch exists but is not wired.
3. Version checks need a decision per module: use `updated_at` (no schema change, but second-precision) or add a `version` column to the critical tables. Recommendation: `updated_at` first, `version` column on Payment Vouchers, Subcontract agreements and Salary periods.
4. Which Staff, Contract and Project fields may live in a browser cache (the table above is a proposal).
5. Whether the change feed should also record who/what changed (richer, larger table) or only that something changed (smaller). Recommendation: only that something changed.

## 10. Backup taken before this work

`/Applications/XAMPP/xamppfiles/htdocs/wims-backups/WIMS_PrePerformanceEngine_20261102/`: database dumps (live-copy DB 328 tables, simulation DBs), code archive (with `.git` and `.env`, without vendor/node_modules/storage), storage archive, `SHA256SUMS.txt`, `README.txt` with the restore commands. Taken at commit `88239f6df`.
