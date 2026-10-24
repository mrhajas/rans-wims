<?php

namespace App\Support\Performance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records the database queries and the pages that were slow while people worked (Settings > Performance & Data > Slow queries), so it is clear what to fix.
 * Only the query text with "?" in place of the values is kept, never the values themselves. A query or page under the limit costs nothing but a comparison.
 */
class SlowLog
{
    private static ?int $queryLimit = null;
    private static int $queries = 0;
    private static int $written = 0;
    private static bool $busy = false;
    private static ?bool $tableReady = null;

    private const MAX_PER_REQUEST = 5;
    private const IGNORED = ['slow_logs', 'sessions', 'jobs', 'cache', 'cache_locks', 'activity_log_archive'];

    public static function listen(): void
    {
        DB::listen(function (QueryExecuted $q) {
            self::$queries++;
            if (self::$busy) {
                return;
            }
            // the limit is read once the request knows which database it works on (configure(), from the middleware); until then nothing is recorded
            if (self::$queryLimit === null || self::$queryLimit <= 0 || $q->time < self::$queryLimit || self::$written >= self::MAX_PER_REQUEST) {
                return;
            }
            foreach (self::IGNORED as $t) {
                if (str_contains($q->sql, '`' . $t . '`')) {
                    return;
                }
            }
            self::write('query', $q->sql, (int) round($q->time));
        });
    }

    /** Reads the limits of Settings > Performance & Data. Called by the middleware, after the request's database is known. */
    public static function configure(): void
    {
        self::$queryLimit = PerfSettings::get('perf_slow_query_ms');
    }

    /** Called when the response has been sent: records the page when it was slow. */
    public static function request(\Illuminate\Http\Request $request): void
    {
        $limit = PerfSettings::get('perf_slow_request_ms');
        if ($limit <= 0) {
            return;
        }
        $start = defined('LARAVEL_START') ? LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? null);
        if (! $start) {
            return;
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($ms < $limit) {
            return;
        }
        $route = optional($request->route())->getName() ?: ('/' . trim($request->path(), '/'));
        self::write('request', $request->method() . ' ' . $route, $ms, self::$queries, (int) round(memory_get_peak_usage(true) / 1048576), $request);
    }

    private static function write(string $kind, string $label, int $ms, ?int $queries = null, ?int $memory = null, ?\Illuminate\Http\Request $request = null): void
    {
        if (self::$tableReady === null) {
            try {
                self::$tableReady = \App\Support\Performance\Tables::has('slow_logs');
            } catch (\Throwable $e) {
                self::$tableReady = false;
            }
        }
        if (! self::$tableReady) {
            return;
        }
        self::$busy = true;
        try {
            $request = $request ?: (app()->bound('request') ? request() : null);
            $label = mb_substr(trim(preg_replace('/\s+/', ' ', $label)), 0, 1500);
            // the same query with a different number of values in IN (?, ?, ?) is one query
            $shape = preg_replace('/\(\s*\?(\s*,\s*\?)+\s*\)/', '(?)', $label);
            DB::table('slow_logs')->insert([
                'kind' => $kind,
                'label' => $label,
                'fingerprint' => sha1($kind . '|' . $shape),
                'duration_ms' => min($ms, 4000000),
                'path' => $request ? mb_substr('/' . trim($request->path(), '/'), 0, 255) : null,
                'method' => $request ? $request->method() : null,
                'user_id' => optional($request && $request->user() ? $request->user() : null)->id,
                'queries' => $queries,
                'memory_mb' => $memory,
                'created_at' => now(),
            ]);
            if ($kind === 'query') {
                self::$written++;
            }
        } catch (\Throwable $e) {
            // recording must never break the page
        } finally {
            self::$busy = false;
        }
    }
}
