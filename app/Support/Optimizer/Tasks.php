<?php

namespace App\Support\Optimizer;

use App\Support\Performance\Housekeeping;
use Illuminate\Support\Facades\DB;

/**
 * The things Settings > Optimize can do. Each task can be looked at first (scan: nothing is changed) and then run. Nothing here deletes business records:
 * tables are rebuilt in place, pictures are saved again smaller under the same name, log files are zipped, and only technical leftovers (expired sessions and
 * cache, temporary files) are removed. The daily clean-up (history to the archive) is the one task that moves data, and it is the same one that runs every night.
 */
class Tasks
{
    public const ALL = ['database', 'images', 'logs', 'technical', 'housekeeping'];

    /** The tasks that are ticked when the page opens. */
    public const DEFAULT = ['database', 'images', 'logs', 'technical'];

    public static function catalog(): array
    {
        return [
            'database' => ['title' => __('Rebuild the database tables'), 'icon' => 'database', 'tone' => '#4f46e5',
                'what' => __('Rebuilds the tables that hold wasted space and refreshes the index figures the database uses to choose the fastest way to read. No record is changed.')],
            'images' => ['title' => __('Compress uploaded pictures'), 'icon' => 'image', 'tone' => '#0d9488',
                'what' => __('Pictures uploaded by people (profile photos, attachments, site diary, ID passes) that are bigger than they need to be are saved again smaller, under the same name. A copy of each original is kept for a few days.')],
            'logs' => ['title' => __('Compress server log files'), 'icon' => 'archive-box', 'tone' => '#d97706',
                'what' => __('Older server log files are zipped. Text compresses to about a tenth of its size. Today\'s log is never touched.')],
            'technical' => ['title' => __('Remove expired technical leftovers'), 'icon' => 'trash', 'tone' => '#dc2626',
                'what' => __('Expired sign-in sessions, expired cache entries, old password-reset tokens and temporary files. Nothing that belongs to a record.')],
            'housekeeping' => ['title' => __('Run the daily clean-up now'), 'icon' => 'refresh', 'tone' => '#2563eb',
                'what' => __('The same clean-up that runs every night: old history moves to the activity archive and old weather readings, read alerts and slow-query records are removed, as set in Performance & Data.')],
        ];
    }

    // ------------------------------------------------------------------------------------------------------------ scan
    /** @return array{bytes:int, items:int, note:string} what could be won (an estimate) */
    public static function scan(string $key): array
    {
        return match ($key) {
            'database' => self::scanDatabase(),
            'images' => self::scanImages(),
            'logs' => self::scanLogs(),
            'technical' => self::scanTechnical(),
            'housekeeping' => ['bytes' => 0, 'items' => 0, 'note' => __('Moves and removes old records; the size gained is measured after it has run.')],
            default => ['bytes' => 0, 'items' => 0, 'note' => ''],
        };
    }

    private static function scanDatabase(): array
    {
        $eligible = self::tablesToRebuild();
        $free = array_sum(array_column($eligible, 'free'));

        return ['bytes' => (int) $free, 'items' => count($eligible), 'note' => count($eligible)
            ? __(':n table(s) hold wasted space.', ['n' => count($eligible)])
            : __('No table holds enough wasted space to rebuild; the index figures are still refreshed.')];
    }

    /** Tables with at least 1 MB of free space inside them. */
    private static function tablesToRebuild(): array
    {
        return array_values(array_filter(Metrics::tables(400), fn ($t) => $t['free'] >= 1024 * 1024));
    }

    private static function scanImages(): array
    {
        $o = OptimizerSettings::all();
        $min = $o['opt_image_min_kb'] * 1024;
        $state = self::state();
        $n = 0;
        $est = 0;
        foreach (Metrics::imageRoots() as $root) {
            foreach (Metrics::files($root, Metrics::IMAGE_EXT) as $p) {
                $size = (int) @filesize($p);
                if (self::unchanged($state, $p, $size)) {
                    continue;
                }
                $d = @getimagesize($p);
                if (! $d) {
                    continue;
                }
                $over = max($d[0], $d[1]) > $o['opt_image_max_px'];
                if ($size < $min && ! $over) {
                    continue;
                }
                $n++;
                $est += (int) ($size * ($over ? 0.6 : 0.3));
            }
        }

        return ['bytes' => $est, 'items' => $n, 'note' => $n ? __(':n picture(s) are bigger than they need to be.', ['n' => $n]) : __('Every picture is already small enough.')];
    }

    private static function scanLogs(): array
    {
        $files = self::logsToZip();
        $bytes = array_sum(array_map(fn ($p) => (int) @filesize($p), $files));

        return ['bytes' => (int) ($bytes * 0.88), 'items' => count($files), 'note' => count($files) ? __(':n log file(s) can be zipped.', ['n' => count($files)]) : __('No old log file is waiting.')];
    }

    private static function scanTechnical(): array
    {
        $n = self::expiredSessions(false) + self::expiredCache(false) + self::oldResetTokens(false) + count(self::tempFiles());
        $bytes = array_sum(array_map(fn ($p) => (int) @filesize($p), self::tempFiles()));

        return ['bytes' => $bytes, 'items' => $n, 'note' => $n ? __(':n leftover(s) can be removed.', ['n' => $n]) : __('Nothing is waiting to be removed.')];
    }

    // ------------------------------------------------------------------------------------------------------------- run
    /**
     * @param  array{deadline:int, run:int, options:array}  $ctx
     * @return array{ok:bool, summary:string, changed:int, bytes:int, notes:array, items:array}
     */
    public static function run(string $key, array $ctx): array
    {
        return match ($key) {
            'database' => self::runDatabase($ctx),
            'images' => self::runImages($ctx),
            'logs' => self::runLogs($ctx),
            'technical' => self::runTechnical($ctx),
            'housekeeping' => self::runHousekeeping($ctx),
            default => ['ok' => false, 'summary' => __('Unknown task'), 'changed' => 0, 'bytes' => 0, 'notes' => [], 'items' => []],
        };
    }

    private static function runDatabase(array $ctx): array
    {
        $rebuilt = [];
        $notes = [];
        $skipped = 0;
        foreach (self::tablesToRebuild() as $t) {
            if (time() > $ctx['deadline']) {
                $skipped++;
                continue;
            }
            try {
                $before = $t['total'];
                $t0 = microtime(true);
                DB::select('OPTIMIZE TABLE `' . str_replace('`', '', $t['name']) . '`');
                $after = (int) (collect(Metrics::tables(400))->firstWhere('name', $t['name'])['total'] ?? $before);
                $rebuilt[] = ['name' => $t['name'], 'before' => $before, 'after' => $after, 'seconds' => round(microtime(true) - $t0, 1)];
            } catch (\Throwable $e) {
                $notes[] = $t['name'] . ': ' . substr($e->getMessage(), 0, 140);
            }
        }
        // the index figures of every table, so the database picks the fastest way to read
        $analysed = 0;
        foreach (DB::select("select table_name as n from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'") as $r) {
            if (time() > $ctx['deadline']) {
                break;
            }
            try {
                DB::select('ANALYZE TABLE `' . str_replace('`', '', $r->n) . '`');
                $analysed++;
            } catch (\Throwable $e) {
                // a table that cannot be analysed (a view or a locked table) is left
            }
        }
        if ($skipped) {
            $notes[] = __(':n table(s) were left for the next run (time limit).', ['n' => $skipped]);
        }
        $saved = array_sum(array_map(fn ($r) => max(0, $r['before'] - $r['after']), $rebuilt));

        return ['ok' => true, 'changed' => count($rebuilt), 'bytes' => (int) $saved, 'notes' => $notes,
            'summary' => __(':a table(s) rebuilt, index figures refreshed for :b table(s).', ['a' => count($rebuilt), 'b' => $analysed]),
            'items' => array_map(fn ($r) => ['name' => $r['name'], 'before' => $r['before'], 'after' => $r['after'], 'note' => $r['seconds'] . ' s'], $rebuilt)];
    }

    private static function runImages(array $ctx): array
    {
        $o = $ctx['options'];
        $min = $o['opt_image_min_kb'] * 1024;
        $state = self::state();
        $items = [];
        $notes = [];
        $changed = 0;
        $saved = 0;
        $looked = 0;
        $rotated = 0;
        $stopped = false;
        $originals = Metrics::dir() . '/originals/' . $ctx['run'];
        foreach (Metrics::imageRoots() as $root) {
            foreach (Metrics::files($root, Metrics::IMAGE_EXT) as $p) {
                if (time() > $ctx['deadline']) {
                    $stopped = true;
                    break 2;
                }
                $size = (int) @filesize($p);
                if (self::unchanged($state, $p, $size)) {
                    continue;
                }
                $d = @getimagesize($p);
                if (! $d || ! in_array($d[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                    continue;
                }
                $over = max($d[0], $d[1]) > $o['opt_image_max_px'];
                if ($size < $min && ! $over) {
                    continue;
                }
                $looked++;
                $r = self::compressOne($p, $d, $size, $o, $over, $originals, $rotated);
                if ($r === null) {
                    $state[$p] = [$size, (int) @filemtime($p)]; // looked at, nothing to gain: not looked at again until it changes
                    continue;
                }
                $changed++;
                $saved += $size - $r['after'];
                $state[$p] = [$r['after'], (int) @filemtime($p)];
                if (count($items) < 400) {
                    $items[] = ['name' => str_replace([base_path() . '/', public_path() . '/'], '', $p), 'before' => $size, 'after' => $r['after'], 'note' => $d[0] . 'x' . $d[1] . ($r['resized'] ? ' > ' . $r['w'] . 'x' . $r['h'] : '')];
                }
            }
        }
        self::saveState($state);
        if ($rotated) {
            $notes[] = __(':n picture(s) carry a rotation mark and were left as they are.', ['n' => $rotated]);
        }
        if ($stopped) {
            $notes[] = __('The time limit was reached: run it again to continue with the rest.');
        }
        if ($changed && $o['opt_keep_originals_days'] > 0) {
            $notes[] = __('The originals are kept for :d day(s) in storage/app/optimizer/originals and then removed; the space they hold is freed then.', ['d' => $o['opt_keep_originals_days']]);
        }
        $purged = self::purgeOriginals($o['opt_keep_originals_days']);
        if ($purged) {
            $notes[] = __(':n old folder(s) of originals were removed.', ['n' => $purged]);
        }

        return ['ok' => true, 'changed' => $changed, 'bytes' => (int) $saved, 'notes' => $notes, 'items' => $items,
            'summary' => $changed ? __(':n picture(s) compressed.', ['n' => $changed]) : __('No picture needed compressing.'), 'looked' => $looked];
    }

    /** @return array{after:int, resized:bool, w:int, h:int}|null null = left as it was */
    private static function compressOne(string $p, array $d, int $size, array $o, bool $over, string $originalsDir, int &$rotated): ?array
    {
        [$w, $h, $type] = $d;
        if ($w * $h > 36_000_000) {
            return null; // too big to open safely on a shared server
        }
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $ex = @exif_read_data($p);
            if (! empty($ex['Orientation']) && (int) $ex['Orientation'] !== 1) {
                $rotated++;

                return null; // saving again would drop the rotation mark and the picture would turn on screen
            }
        }
        @ini_set('memory_limit', '512M');
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($p),
            IMAGETYPE_PNG => @imagecreatefrompng($p),
            IMAGETYPE_WEBP => @imagecreatefromwebp($p),
            default => false,
        };
        if (! $img) {
            return null;
        }
        $nw = $w;
        $nh = $h;
        if ($over) {
            $k = $o['opt_image_max_px'] / max($w, $h);
            $nw = max(1, (int) round($w * $k));
            $nh = max(1, (int) round($h * $k));
            $scaled = imagescale($img, $nw, $nh, IMG_BICUBIC);
            if ($scaled) {
                imagedestroy($img);
                $img = $scaled;
            }
        }
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        $tmp = $p . '.opt' . getmypid();
        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($img, $tmp, $o['opt_image_quality']),
            IMAGETYPE_PNG => imagepng($img, $tmp, 9),
            IMAGETYPE_WEBP => imagewebp($img, $tmp, $o['opt_image_quality']),
            default => false,
        };
        imagedestroy($img);
        $after = $ok ? (int) @filesize($tmp) : 0;
        if (! $ok || $after <= 0 || (! $over && $after > $size * 0.9)) {
            @unlink($tmp);

            return null; // not worth it: less than a tenth is gained
        }
        if ($over && $after >= $size) {
            @unlink($tmp);

            return null;
        }
        if ($o['opt_keep_originals_days'] > 0) {
            $rel = ltrim(str_replace([base_path(), '\\'], ['', '/'], $p), '/');
            $dest = $originalsDir . '/' . $rel;
            @mkdir(dirname($dest), 0775, true);
            if (! @copy($p, $dest)) {
                @unlink($tmp);

                return null; // no copy of the original, no change
            }
        }
        $mtime = (int) @filemtime($p);
        $perm = @fileperms($p) & 0777;
        if (! @rename($tmp, $p)) {
            @unlink($tmp);

            return null;
        }
        @chmod($p, $perm ?: 0644);
        @touch($p, $mtime);

        return ['after' => $after, 'resized' => $over && ($nw !== $w || $nh !== $h), 'w' => $nw, 'h' => $nh];
    }

    private static function purgeOriginals(int $keepDays): int
    {
        $base = Metrics::dir() . '/originals';
        if (! is_dir($base)) {
            return 0;
        }
        $n = 0;
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ($keepDays === 0 || filemtime($dir) < time() - $keepDays * 86400) {
                self::rmdirAll($dir);
                $n++;
            }
        }

        return $n;
    }

    private static function rmdirAll(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** Old originals are removed by every run and by the nightly clean-up. */
    public static function purgeOldOriginals(): int
    {
        return self::purgeOriginals(OptimizerSettings::get('opt_keep_originals_days'));
    }

    private static function runLogs(array $ctx): array
    {
        $done = 0;
        $before = 0;
        $after = 0;
        $items = [];
        foreach (self::logsToZip() as $p) {
            if (time() > $ctx['deadline']) {
                break;
            }
            $size = (int) @filesize($p);
            $gz = $p . '.gz';
            $in = @fopen($p, 'rb');
            $out = @gzopen($gz, 'wb9');
            if (! $in || ! $out) {
                @fclose($in ?: null);
                continue;
            }
            while (! feof($in)) {
                gzwrite($out, (string) fread($in, 1 << 20));
            }
            fclose($in);
            gzclose($out);
            $gzSize = (int) @filesize($gz);
            if ($gzSize > 0 && $gzSize < $size) {
                @touch($gz, (int) @filemtime($p));
                @unlink($p);
                $done++;
                $before += $size;
                $after += $gzSize;
                $items[] = ['name' => basename($p), 'before' => $size, 'after' => $gzSize, 'note' => ''];
            } else {
                @unlink($gz);
            }
        }

        return ['ok' => true, 'changed' => $done, 'bytes' => $before - $after, 'notes' => [], 'items' => $items,
            'summary' => $done ? __(':n log file(s) zipped.', ['n' => $done]) : __('No old log file was waiting.')];
    }

    /** Log files older than the set number of days (never today's, never one already zipped). */
    private static function logsToZip(): array
    {
        $days = OptimizerSettings::get('opt_log_gzip_days');
        $out = [];
        foreach (glob(storage_path('logs') . '/*.log') ?: [] as $p) {
            if (is_file($p) && filemtime($p) < time() - $days * 86400 && filesize($p) > 4096) {
                $out[] = $p;
            }
        }

        return $out;
    }

    private static function runTechnical(array $ctx): array
    {
        $s = self::expiredSessions(true);
        $c = self::expiredCache(true);
        $t = self::oldResetTokens(true);
        $bytes = 0;
        $files = 0;
        foreach (self::tempFiles() as $p) {
            $bytes += (int) @filesize($p);
            if (@unlink($p)) {
                $files++;
            }
        }

        return ['ok' => true, 'changed' => $s + $c + $t + $files, 'bytes' => $bytes, 'notes' => [], 'items' => [
            ['name' => __('Expired sign-in sessions'), 'before' => $s, 'after' => 0, 'note' => 'count'],
            ['name' => __('Expired cache entries'), 'before' => $c, 'after' => 0, 'note' => 'count'],
            ['name' => __('Old password-reset tokens'), 'before' => $t, 'after' => 0, 'note' => 'count'],
            ['name' => __('Temporary files'), 'before' => $files, 'after' => 0, 'note' => 'count'],
        ], 'summary' => __(':n leftover(s) removed.', ['n' => $s + $c + $t + $files])];
    }

    private static function expiredSessions(bool $delete): int
    {
        $life = (int) config('session.lifetime', 120) * 60;
        $n = 0;
        try {
            if (config('session.driver') === 'database' && \App\Support\Performance\Tables::has(config('session.table', 'sessions'))) {
                $q = DB::table(config('session.table', 'sessions'))->where('last_activity', '<', time() - $life);
                $n = $delete ? $q->delete() : $q->count();
            } elseif (config('session.driver') === 'file') {
                foreach (glob(config('session.files', storage_path('framework/sessions')) . '/*') ?: [] as $p) {
                    if (is_file($p) && filemtime($p) < time() - $life) {
                        $n++;
                        if ($delete) {
                            @unlink($p);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            return $n;
        }

        return (int) $n;
    }

    private static function expiredCache(bool $delete): int
    {
        try {
            if (config('cache.default') === 'database' && \App\Support\Performance\Tables::has(config('cache.stores.database.table', 'cache'))) {
                $q = DB::table(config('cache.stores.database.table', 'cache'))->where('expiration', '<', time());

                return (int) ($delete ? $q->delete() : $q->count());
            }
        } catch (\Throwable $e) {
        }

        return 0;
    }

    private static function oldResetTokens(bool $delete): int
    {
        try {
            if (\App\Support\Performance\Tables::has('password_reset_tokens')) {
                $q = DB::table('password_reset_tokens')->where('created_at', '<', now()->subDays(2));

                return (int) ($delete ? $q->delete() : $q->count());
            }
        } catch (\Throwable $e) {
        }

        return 0;
    }

    /** Files in the temporary folders older than a week. */
    private static function tempFiles(): array
    {
        $out = [];
        foreach ([storage_path('app/tmp'), storage_path('app/temp'), storage_path('app/livewire-tmp')] as $dir) {
            foreach (Metrics::files($dir) as $p) {
                if (filemtime($p) < time() - 7 * 86400) {
                    $out[] = $p;
                }
            }
        }

        return $out;
    }

    private static function runHousekeeping(array $ctx): array
    {
        $r = Housekeeping::run();
        $moved = (int) ($r['activity_archived'] ?? 0);
        $removed = (int) (($r['notifications_removed'] ?? 0) + ($r['weather_removed'] ?? 0) + ($r['slow_removed'] ?? 0) + ($r['failed_jobs_removed'] ?? 0) + ($r['reset_tokens_removed'] ?? 0) + ($r['sessions_removed'] ?? 0));

        return ['ok' => true, 'changed' => $moved + $removed, 'bytes' => 0, 'notes' => [], 'summary' => __(':a activity record(s) moved to the archive, :b old record(s) removed.', ['a' => number_format($moved), 'b' => number_format($removed)]),
            'items' => [
                ['name' => __('Activity records moved to the archive'), 'before' => $moved, 'after' => 0, 'note' => 'count'],
                ['name' => __('Read alerts removed'), 'before' => (int) ($r['notifications_removed'] ?? 0), 'after' => 0, 'note' => 'count'],
                ['name' => __('Weather readings removed'), 'before' => (int) ($r['weather_removed'] ?? 0), 'after' => 0, 'note' => 'count'],
                ['name' => __('Slow-query records removed'), 'before' => (int) ($r['slow_removed'] ?? 0), 'after' => 0, 'note' => 'count'],
            ]];
    }

    // ------------------------------------------------------------------------------------- what was already looked at
    private static function statePath(): string
    {
        return Metrics::dir() . '/state.json';
    }

    private static function state(): array
    {
        $p = self::statePath();

        return is_file($p) ? (json_decode((string) @file_get_contents($p), true) ?: []) : [];
    }

    private static function saveState(array $s): void
    {
        @mkdir(Metrics::dir(), 0775, true);
        @file_put_contents(self::statePath(), json_encode($s), LOCK_EX);
    }

    private static function unchanged(array $state, string $p, int $size): bool
    {
        return isset($state[$p]) && $state[$p][0] === $size && $state[$p][1] === (int) @filemtime($p);
    }
}
