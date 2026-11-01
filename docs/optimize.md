# Settings > Optimize

Makes the data that is already on the server smaller and faster, measures what was gained and keeps an audit history that can be printed.

* Page: `/settings/optimize` (Settings > "Open Optimize", Launcher: "Optimize"). History detail: `/settings/optimize/runs/{id}`, print `/print`, PDF `/pdf` (WIMS print document, logged in the Download / Print log).
* Permissions (Roles screen, group "Optimize"): `read-optimizer` (open the page, history, print) and `run-optimizer` (scan, run, change the numbers). **Nobody has them by default except the Super Admin**, who passes every gate. Give them to any role on the Roles screen.
* Global Feature Control key: `optimizer` (route prefix `optimizer.`).
* Running asks for the person's own password.

## What it does (each task can be ticked or not)
| Task | What happens | Reversible |
|---|---|---|
| Rebuild the database tables | `OPTIMIZE TABLE` on tables holding at least 1 MB of free space inside, then `ANALYZE TABLE` on every table | yes (no record changes) |
| Compress uploaded pictures | JPEG/PNG/WebP in profile photos, `public/uploads`, attachments, archive, site diary, ID passes, account attachments that are bigger than `opt_image_min_kb` or longer than `opt_image_max_px` are saved again (quality `opt_image_quality`), same name. A copy of the original is kept in `storage/app/optimizer/originals/{run}` for `opt_keep_originals_days` days, then removed by the next run and by the nightly clean-up. Pictures with an EXIF rotation mark are left alone | copy kept |
| Compress server log files | log files older than `opt_log_gzip_days` become `.log.gz` | readable with any unzip tool |
| Remove expired technical leftovers | expired sessions and cache rows, old password-reset tokens, temp files older than a week | n/a (expired) |
| Run the daily clean-up now | the same `Housekeeping::run()` that runs every night (history to the activity archive ...) | as the nightly one |

Backups (`storage/app/backups`, `db-backups`) and the simulation data are never touched.

## What is measured
Before and after every run: database size including the free space inside its files, picture size, log size, kept originals, free disk space, and the time of a fixed set of seven typical database reads (`SpeedProbe`, middle of five tries). The audit record (`optimizer_runs`) keeps who, when, the tasks, the numbers used, the before and after figures, and what each task did (first 400 changed files). The same event goes to the activity log.

Honest limits: speed is a measurement of database reads only and small changes are noise; picture gains depend on what people uploaded; the database gain is only real when the rebuilt tables really held free space.

## Settings kept (table `settings`, keys `opt_*`)
`opt_image_max_px` 1600, `opt_image_quality` 80, `opt_image_min_kb` 250, `opt_keep_originals_days` 14, `opt_log_gzip_days` 2, `opt_seconds` 90 (a run stops starting new work after this; run again to continue).

Deploy: the migration `2026_12_27_100000_create_optimizer_runs_and_permissions` creates the table, the two permissions and the feature row (cPanel deploy already runs `migrate --force`).
