<?php

namespace App\Support\WimsLogs;

use App\Models\User;
use App\Models\WimsLogArchive;
use App\Models\WimsLogRun;
use App\Support\Optimizer\Metrics;
use App\Support\Optimizer\SpeedProbe;
use App\Support\Performance\Tables;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The archiving itself. A run looks at each log in turn and moves the records that are older than that log's number of live days into compressed archive files.
 *
 * Nothing is removed from the database before it is safely in the archive: a batch of records is written, then every record of it is read back from the file and compared
 * with the original, and only then are those rows deleted. A batch that does not verify is not deleted, and the run says so.
 *
 * A run is done in steps (begin, step for each log until it has no more, step "optimize" until it has no more, finish) so a screen can show progress and no single request
 * runs for long; run() does them all in a row (the command line, the schedule, tests).
 */
class Archiver
{
    public const BATCH = 500;
    public const STALE_MINUTES = 120;

    /** The records of a log that are old enough to move and may be moved. */
    public static function due(array $source, ?Carbon $cut = null, ?string $key = null): Builder
    {
        $cut ??= now()->subDays(WimsLogSettings::keepDays($key ?? ''));
        $q = DB::table($source['table'])->where($source['date'], '<', $cut->toDateTimeString());
        if (! empty($source['eligible'])) {
            ($source['eligible'])($q);
        }
        // a record that was put back by hand stays live: the next run does not archive it again
        if ($key !== null && Tables::has('wims_log_restores')) {
            $col = ($source['pk_type'] ?? 'int') === 'int' ? 'record_num' : 'record_key';
            $q->whereNotExists(function ($s) use ($key, $col, $source) {
                $s->select(DB::raw(1))->from('wims_log_restores')->where('wims_log_restores.source', $key)->whereColumn('wims_log_restores.' . $col, $source['table'] . '.' . $source['pk']);
            });
        }

        return $q;
    }

    /**
     * What a run would do now, log by log. Nothing is changed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function preview(array $keepOverride = []): array
    {
        $out = [];
        $sizes = self::tableSizes(array_column(Sources::all(), 'table'));
        foreach (Sources::all() as $key => $src) {
            if (! Tables::has($src['table'])) {
                continue;
            }
            // a run by hand may ask for another number of live days than the saved one (the saved setting is not changed)
            $keep = isset($keepOverride[$key]) ? max(WimsLogSettings::KEEP_MIN, min(WimsLogSettings::KEEP_MAX, (int) $keepOverride[$key])) : WimsLogSettings::keepDays($key);
            $cut = now()->subDays($keep);
            $due = (int) self::due($src, $cut, $key)->count();
            $oldest = $due ? self::due($src, $cut, $key)->min($src['date']) : null;
            $t = $sizes[$src['table']] ?? ['rows' => 0, 'bytes' => 0, 'avg_row' => 0, 'free' => 0];
            $live = (int) DB::table($src['table'])->count();
            $out[] = [
                'key' => $key, 'label' => $src['label'], 'help' => $src['help'], 'table' => $src['table'], 'on' => WimsLogSettings::isOn($key), 'keep_days' => $keep,
                'live_rows' => $live, 'live_bytes' => (int) $t['bytes'], 'due_rows' => $due, 'due_bytes' => (int) round($due * (float) $t['avg_row']),
                'oldest_due' => $oldest, 'cutoff' => $cut->toDateString(),
            ];
        }

        return $out;
    }

    /** The size of the database now (zeros where the server cannot say, e.g. tests on another engine). */
    public static function dbSize(): array
    {
        try {
            return Metrics::database();
        } catch (\Throwable $e) {
            return ['bytes' => 0, 'free' => 0, 'tables' => 0];
        }
    }

    /** @param  array<int, string>  $tables @return array<string, array{rows:int, bytes:int, avg_row:float, free:int}> */
    public static function tableSizes(array $tables): array
    {
        try {
            DB::statement('SET SESSION information_schema_stats_expiry = 0'); // MySQL 8 otherwise reports sizes up to a day old
        } catch (\Throwable $e) {
            // not MySQL 8
        }
        if (! $tables) {
            return [];
        }
        $in = implode(',', array_fill(0, count($tables), '?'));
        try {
            $rows = DB::select("select table_name as name, table_rows as rows_n, data_length, index_length, data_free, avg_row_length from information_schema.tables where table_schema = database() and table_name in ($in)", $tables);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[$r->name] = ['rows' => (int) $r->rows_n, 'bytes' => (int) $r->data_length + (int) $r->index_length, 'free' => (int) $r->data_free, 'avg_row' => (float) $r->avg_row_length];
        }

        return $out;
    }

    /** @param  array<int, string>|null  $sources the logs to look at (null: every log that is switched on) */
    public static function begin(string $trigger, ?User $by, ?array $sources = null, array $keepOverride = []): WimsLogRun
    {
        // a run that was started and never finished (the browser was closed) stops blocking after a while
        WimsLogRun::query()->where('status', 'running')->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => 'failed', 'error' => 'It did not finish and was closed by the next run.', 'finished_at' => now()]);
        if (WimsLogRun::query()->where('status', 'running')->exists()) {
            throw new \RuntimeException(__('Another run is in progress. Wait for it to finish.'));
        }

        $keys = array_values(array_filter($sources ?? Sources::keys(), fn ($k) => Sources::get($k) && Tables::has(Sources::get($k)['table']) && ($sources !== null || WimsLogSettings::isOn($k))));
        @set_time_limit(0);
        $tables = array_map(fn ($k) => Sources::get($k)['table'], $keys);

        return WimsLogRun::create([
            'account_id' => optional($by)->account_id, 'user_id' => optional($by)->id, 'user_name' => optional($by)->name ?: __('System'),
            'trigger' => $trigger, 'status' => 'running', 'sources' => $keys, 'started_at' => now(),
            'options' => ['keep_days' => collect($keys)->mapWithKeys(fn ($k) => [$k => isset($keepOverride[$k]) ? max(WimsLogSettings::KEEP_MIN, min(WimsLogSettings::KEEP_MAX, (int) $keepOverride[$k])) : WimsLogSettings::keepDays($k)])->all(), 'optimized' => [], 'by_hand_days' => array_keys(array_intersect_key($keepOverride, array_flip($keys)))],
            'before' => ['db' => self::dbSize(), 'tables' => self::tableSizes($tables), 'at' => now()->toDateTimeString()],
            'speed_before' => SpeedProbe::run(),
        ]);
    }

    /**
     * Moves records of one log until none are left to move or the time of one step is used up. The answer says `more` when the caller should ask again.
     *
     * @return array{ok: bool, more: bool, rows: int, original: int, compressed: int, error: ?string}
     */
    public static function step(WimsLogRun $run, string $key, ?int $seconds = null): array
    {
        abort_unless($run->status === 'running' && in_array($key, (array) $run->sources, true), 422);
        $src = Sources::get($key);
        $seconds ??= WimsLogSettings::get('wimslogs_seconds');
        $deadline = microtime(true) + $seconds;
        $cut = now()->subDays((int) ($run->options['keep_days'][$key] ?? WimsLogSettings::keepDays($key)));
        $rel = ArchiveStore::rel($key, $run->id);
        $arc = WimsLogArchive::firstOrCreate(['run_id' => $run->id, 'source' => $key], ['table_name' => $src['table'], 'path' => $rel, 'users_indexed' => true]);
        $result = ['ok' => true, 'more' => false, 'rows' => 0, 'original' => 0, 'compressed' => 0, 'error' => null];
        if ($arc->closed) {
            return $result;
        }
        $run->touch();
        $skipKey = 'wimslogs:skip:' . $run->id . ':' . $key;
        $skipIds = (array) Cache::get($skipKey, []);

        try {
            while (microtime(true) < $deadline) {
                $q = self::due($src, $cut, $key)->orderBy($src['pk'])->limit(self::BATCH);
                if ($skipIds) {
                    $q->whereNotIn($src['pk'], $skipIds);
                }
                $batch = array_map(fn ($o) => (array) $o, $q->get()->all());
                if (! $batch) {
                    break;
                }

                // 1. written, 2. read back and compared, 3. only then removed from the live table
                $w = ArchiveStore::append($rel, $batch, $src['pk'], $src['date'], $src['summary'], $src['user'] ?? null);
                ArchiveStore::verify($rel, $w['entries'], $w['hashes']);
                $ids = array_column($w['entries'], 'i');
                if ($ids) {
                    DB::table($src['table'])->whereIn($src['pk'], $ids)->delete();
                }
                if ($w['skipped']) {
                    $skipIds = array_values(array_unique(array_merge($skipIds, array_filter($w['skipped'], fn ($v) => $v !== null))));
                    Cache::put($skipKey, $skipIds, 86400);
                }

                $dates = array_values(array_filter(array_column($w['entries'], 't')));
                $from = $arc->date_from ? $arc->date_from->toDateTimeString() : null;
                $to = $arc->date_to ? $arc->date_to->toDateTimeString() : null;
                $arc->update([
                    'rows_n' => $arc->rows_n + count($w['entries']), 'original_bytes' => $arc->original_bytes + $w['original'], 'compressed_bytes' => $arc->compressed_bytes + $w['compressed'],
                    'skipped_n' => $arc->skipped_n + count($w['skipped']),
                    'date_from' => $dates ? ($from ? min($from, min($dates)) : min($dates)) : $from,
                    'date_to' => $dates ? ($to ? max($to, max($dates)) : max($dates)) : $to,
                ]);
                $result['rows'] += count($w['entries']);
                $result['original'] += $w['original'];
                $result['compressed'] += $w['compressed'];
                $run->touch();
            }

            $q = self::due($src, $cut, $key);
            if ($skipIds) {
                $q->whereNotIn($src['pk'], $skipIds);
            }
            $result['more'] = $q->exists();
            if (! $result['more']) {
                self::closeArchive($arc);
            } else {
                $arc->update(['stored_bytes' => (int) @filesize(ArchiveStore::bin($rel)) + (int) @filesize(ArchiveStore::idx($rel))]);
            }
        } catch (\Throwable $e) {
            // nothing of the batch that failed was removed from the live table
            $result['ok'] = false;
            $result['more'] = false;
            $result['error'] = substr($e->getMessage(), 0, 300);
            $arc->update(['error' => $result['error']]);
            try {
                self::closeArchive($arc->fresh());
            } catch (\Throwable $e2) {
                // the files stay as they are; the records already moved are verified and listed
            }
        }

        return $result;
    }

    /** Seals an archive: index compressed, checksum taken. An archive with no record at all is not kept. */
    private static function closeArchive(WimsLogArchive $arc): void
    {
        if ($arc->closed) {
            return;
        }
        $rel = (string) $arc->path;
        if ((int) $arc->rows_n === 0) {
            foreach ([ArchiveStore::bin($rel), ArchiveStore::idx($rel), ArchiveStore::idxGz($rel)] as $f) {
                @unlink($f);
            }
            $arc->delete();

            return;
        }
        $c = ArchiveStore::close($rel);
        $arc->update(['sha256' => $c['sha256'], 'stored_bytes' => $c['stored'], 'verified' => $arc->error === null, 'closed' => true]);
    }

    /**
     * Compacts, one table at a time, the tables that gave records away, so the database files really get smaller (without it the freed space stays reserved inside them).
     *
     * @return array{ok: bool, more: bool, table: ?string, before: int, after: int}
     */
    public static function optimizeStep(WimsLogRun $run): array
    {
        abort_unless($run->status === 'running', 422);
        $options = (array) $run->options;
        $done = (array) ($options['optimized'] ?? []);
        $tables = [];
        foreach ($run->archives()->where('rows_n', '>', 0)->pluck('table_name')->all() as $t) {
            $tables[$t] = true;
        }
        $pending = array_values(array_diff(array_keys($tables), array_keys($done)));
        if (! WimsLogSettings::get('wimslogs_optimize') || ! $pending) {
            return ['ok' => true, 'more' => false, 'table' => null, 'before' => 0, 'after' => 0];
        }
        $t = $pending[0];
        @set_time_limit(0);
        $before = self::tableSizes([$t])[$t] ?? ['bytes' => 0, 'free' => 0];
        $ok = true;
        try {
            DB::select('optimize table `' . str_replace('`', '', $t) . '`');
        } catch (\Throwable $e) {
            $ok = false; // not allowed or not supported: the records are archived all the same, the space is given back by the next Optimize
        }
        $after = self::tableSizes([$t])[$t] ?? ['bytes' => 0, 'free' => 0];
        $done[$t] = ['ok' => $ok, 'before' => (int) $before['bytes'] + (int) $before['free'], 'after' => (int) $after['bytes'] + (int) $after['free']];
        $options['optimized'] = $done;
        $run->update(['options' => $options]);

        return ['ok' => $ok, 'more' => count($pending) > 1, 'table' => $t, 'before' => $done[$t]['before'], 'after' => $done[$t]['after']];
    }

    public static function finish(WimsLogRun $run, ?User $by = null): WimsLogRun
    {
        foreach ($run->archives()->where('closed', false)->get() as $arc) {
            try {
                self::closeArchive($arc);
            } catch (\Throwable $e) {
                $arc->update(['error' => substr($e->getMessage(), 0, 300)]);
            }
        }
        $archives = $run->archives()->get();
        $tables = $archives->pluck('table_name')->unique()->values()->all();
        $afterTables = self::tableSizes($tables);
        $beforeTables = (array) ($run->before['tables'] ?? []);
        $freed = 0;
        foreach ($tables as $t) {
            $b = $beforeTables[$t] ?? null;
            $a = $afterTables[$t] ?? null;
            if ($b && $a) {
                $freed += ((int) $b['bytes'] + (int) $b['free']) - ((int) $a['bytes'] + (int) $a['free']);
            }
        }
        $speedAfter = SpeedProbe::run();
        $failed = $archives->filter(fn ($a) => $a->error !== null)->count();
        $status = $failed === 0 ? 'done' : ($failed >= max(1, $archives->count()) ? 'failed' : 'partial');

        $run->update([
            'status' => $status, 'after' => ['db' => self::dbSize(), 'tables' => $afterTables, 'at' => now()->toDateTimeString()], 'speed_after' => $speedAfter,
            'speed_before_ms' => ((array) $run->speed_before)['total_ms'] ?? null, 'speed_after_ms' => $speedAfter['total_ms'],
            'rows_archived' => (int) $archives->sum('rows_n'), 'original_bytes' => (int) $archives->sum('original_bytes'), 'stored_bytes' => (int) $archives->sum('stored_bytes'),
            'db_freed_bytes' => $freed, 'seconds' => (int) abs(now()->diffInSeconds($run->started_at)), 'finished_at' => now(),
            'error' => $failed ? $archives->pluck('error')->filter()->implode(' | ') : null,
        ]);
        $run = $run->fresh();

        try {
            log_activity(__('Logs archived'), ['run' => $run->ref(), 'trigger' => $run->trigger, 'status' => $status, 'rows' => $run->rows_archived, 'original_bytes' => $run->original_bytes, 'stored_bytes' => $run->stored_bytes, 'db_freed_bytes' => $run->db_freed_bytes], $by ?: $run, 'Setting');
        } catch (\Throwable $e) {
            // the run's own record is the history
        }

        return $run;
    }

    /** Every step in a row (command line, schedule, tests). */
    public static function run(string $trigger, ?User $by = null, ?array $sources = null, ?int $seconds = null, array $keepOverride = []): WimsLogRun
    {
        $run = self::begin($trigger, $by, $sources, $keepOverride);
        try {
            foreach ((array) $run->sources as $key) {
                for ($i = 0; $i < 5000; $i++) {
                    $r = self::step($run->fresh(), $key, $seconds ?? 60);
                    if (! $r['more']) {
                        break;
                    }
                }
            }
            for ($i = 0; $i < 50; $i++) {
                if (! self::optimizeStep($run->fresh())['more']) {
                    break;
                }
            }
        } finally {
            $run = self::finish($run->fresh(), $by);
        }

        return $run;
    }
}
