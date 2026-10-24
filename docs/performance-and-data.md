# Performance & Data (Settings > Performance & Data, Super Admin only)

Built 2026-10-25. Every number is a setting (table `settings`, keys `perf_*`, defaults in `App\Support\Performance\PerfSettings`).

| Setting | Default | What it does |
|---|---|---|
| perf_activity_keep_months | 12 | Activity history older than this moves from `activity_log` to `activity_log_archive` (never deleted; 0 = never move). Site Diary history stays live. |
| perf_notification_keep_days | 180 | Read bell alerts older than this are removed (unread stay; 0 = keep). |
| perf_weather_keep_days | 30 | Old weather readings removed (0 = keep). |
| perf_log_keep_days | 14 | Daily `laravel-*.log` files older than this are removed; `schedule.log` over 5 MB keeps its last 1 MB. |
| perf_slow_keep_days | 30 | How long slow-query / slow-page records are kept. |
| perf_slow_query_ms / perf_slow_request_ms | 300 / 1500 | A query / page slower than this is recorded in `slow_logs` (0 = off). Only the query text with `?`, never values. |
| perf_queue_background | 0 | 1 = SMS, e-mail and bell alerts are queued and sent by the worker. |

* **Nightly clean-up** `housekeeping:run` at 02:10 (scheduler). "Run clean-up now" on the page does the same after the person's password.
* **Activity archive**: browse (filters) and download as CSV on the page; the download route name contains `download`, so it is in the Download / Print log.
* **Sending in the background**: the scheduler (`schedule:run` every minute, already on the server) starts `queue:work --max-time=55` in the background and `queue:beat` records that it is alive. Background sending is used only while the beat is under 150 s old, otherwise WIMS sends at once (nothing waits in a queue nobody empties). Needs `jobs` table (migration 2026_12_13_100000, runs on deploy).
* **Settings cache (optional)**: create the empty file `/home/ranscoco/config-cache.on` to make the deploy run `config:cache`. After that a hand edit of `.env` only takes effect on the next deploy or after `php artisan config:clear`. All direct `env()` reads in the code were moved to config; `/install*` is blocked by `BlockInstallerWhenInstalled` because the installer package checks `env()`.
* Only the person's own language file is downloaded at start (others on switching).
* Deploy: migration runs automatically (`.cpanel.yml`); run `php artisan features:reconcile --apply` once so the `performance_data` Feature Control row exists.
