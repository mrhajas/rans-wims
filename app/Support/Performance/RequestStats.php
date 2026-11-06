<?php

namespace App\Support\Performance;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long WIMS really takes to answer (System Health > Speed of the server).
 *
 * Per request, after the answer has been sent: ONE short line is appended to a file (time, milliseconds, status, page or api, module, number of queries). Nothing is written to the database
 * while a person waits. Every hour (the scheduler runs `perf:aggregate` every 5 minutes) the lines are added up into `perf_request_stats`: per hour, kind and module the count, total and
 * slowest time, the errors and a histogram. The typical time and the 95th / 99th percentile are read from the histogram (accurate to the width of one bar).
 *
 * Only the module name is kept (the first part of the route name, "items" for "items.index"); no address, person, query text or value.
 */
class RequestStats
{
    /** Upper limits (ms) of the histogram bars; the last bar is everything slower. */
    public const BOUNDS = [50, 100, 200, 400, 800, 1500, 3000, 6000, 12000];
    public const KEEP_DAYS = 35;

    /** Set only by the tests, so they never touch the real files. */
    public static ?string $dirOverride = null;

    public static function file(): string
    {
        return (self::$dirOverride ?? storage_path('app/perf')) . '/requests.log';
    }

    /** Called when the answer has been sent. Never throws. */
    public static function record(Request $request, $response): void
    {
        try {
            if (! in_array($request->method(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || ! self::enabled()) {
                return;
            }
            $start = defined('LARAVEL_START') ? LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? null);
            if (! $start) {
                return;
            }
            $ms = (int) round((microtime(true) - $start) * 1000);
            $route = $request->route();
            $name = $route ? (string) $route->getName() : '';
            $module = $name !== '' ? explode('.', $name)[0] : (explode('/', trim($request->path(), '/'))[0] ?: 'home');
            $module = preg_replace('/[^A-Za-z0-9_\-]/', '', $module) ?: 'other';
            // a page is a visit a person asked for (a full load or an in-app visit); everything else is a call the page makes in the background
            $kind = ($request->headers->has('X-Inertia') || ($request->isMethod('GET') && $request->acceptsHtml() && ! $request->ajax())) ? 'page' : 'api';
            $dir = dirname(self::file());
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents(self::file(), time() . "\t" . $ms . "\t" . $response->getStatusCode() . "\t" . $kind . "\t" . substr($module, 0, 60) . "\t" . SlowLog::queryCount() . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // measuring must never break an answer
        }
    }

    /** Measured only while System Health is on in Feature Control and the setting is not 0. */
    private static function enabled(): bool
    {
        static $on = null;
        if ($on === null) {
            $on = PerfSettings::get('perf_request_stats') === 1 && \App\Services\FeatureManager::isEnabled('system_health');
        }

        return $on;
    }

    /** Adds the recorded lines up into the table. Returns how many requests were added. */
    public static function aggregate(): int
    {
        if (! Schema::hasTable('perf_request_stats')) {
            return 0;
        }
        $dir = dirname(self::file());
        if (is_file(self::file()) && filesize(self::file()) > 0) {
            @rename(self::file(), $dir . '/batch-' . str_replace('.', '', sprintf('%.4f', microtime(true))) . '.log'); // new requests start a new file
        }
        $added = 0;
        foreach (glob($dir . '/batch-*.log') ?: [] as $batch) {
            if (time() - (int) @filemtime($batch) < 20) {
                continue; // a request may still be writing its line to this file
            }
            $rows = [];
            $fh = @fopen($batch, 'r');
            while ($fh && ($line = fgets($fh)) !== false) {
                $p = explode("\t", rtrim($line, "\n"));
                if (count($p) < 6) {
                    continue;
                }
                [$t, $ms, $status, $kind, $module, $q] = $p;
                $bucket = date('Y-m-d H:00:00', (int) $t);
                $k = $bucket . '|' . $kind . '|' . $module;
                $r = $rows[$k] ?? ($rows[$k] = ['bucket' => $bucket, 'kind' => $kind, 'module' => $module, 'n' => 0, 'sum_ms' => 0, 'max_ms' => 0, 'errors' => 0, 'client_errors' => 0, 'sum_queries' => 0] + array_fill_keys(['h0', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'h8', 'h9'], 0));
                $ms = max(0, (int) $ms);
                $r['n']++;
                $r['sum_ms'] += $ms;
                $r['max_ms'] = max($r['max_ms'], $ms);
                $r['errors'] += (int) $status >= 500 ? 1 : 0;
                $r['client_errors'] += ((int) $status >= 400 && (int) $status < 500) ? 1 : 0;
                $r['sum_queries'] += (int) $q;
                $r['h' . self::bar($ms)]++;
                $rows[$k] = $r;
            }
            if ($fh) {
                fclose($fh);
            }
            foreach ($rows as $r) {
                $added += $r['n'];
                self::addTo($r);
            }
            @unlink($batch);
        }
        DB::table('perf_request_stats')->where('bucket', '<', now()->subDays(self::KEEP_DAYS))->delete();

        return $added;
    }

    /** Adds one batch's figures to the hour's row (or creates it). Every number comes from our own counting, never from the request. */
    private static function addTo(array $r): void
    {
        $q = DB::table('perf_request_stats')->where('bucket', $r['bucket'])->where('kind', $r['kind'])->where('module', $r['module']);
        if (! $q->exists()) {
            DB::table('perf_request_stats')->insert($r);

            return;
        }
        $set = [];
        foreach (['n', 'sum_ms', 'errors', 'client_errors', 'sum_queries', 'h0', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'h8', 'h9'] as $c) {
            $set[$c] = DB::raw($c . ' + ' . (int) $r[$c]);
        }
        $max = (int) $r['max_ms'];
        $set['max_ms'] = DB::raw('case when max_ms > ' . $max . ' then max_ms else ' . $max . ' end');
        $q->update($set);
    }

    /** Which histogram bar a time in milliseconds belongs to (0 = fastest). */
    public static function bar(int $ms): int
    {
        foreach (self::BOUNDS as $i => $b) {
            if ($ms <= $b) {
                return $i;
            }
        }

        return count(self::BOUNDS);
    }

    /** The time below which `$p` percent of the requests were answered, from merged histogram counts (read inside the bar, so it is exact to the bar's width). */
    public static function percentile(array $counts, float $p): ?int
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return null;
        }
        $target = $total * $p / 100;
        $seen = 0;
        $lower = 0;
        foreach (array_values($counts) as $i => $c) {
            $upper = self::BOUNDS[$i] ?? (self::BOUNDS[count(self::BOUNDS) - 1] * 2);
            if ($c > 0 && $seen + $c >= $target) {
                return (int) round($lower + ($upper - $lower) * (($target - $seen) / $c));
            }
            $seen += $c;
            $lower = $upper;
        }

        return $lower;
    }

    /**
     * The figures for System Health: pages and background calls over the last `$hours` hours (typical, 95th and 99th percentile, average, errors), and the slowest modules.
     *
     * @return array<string, mixed>
     */
    public static function summary(int $hours = 24): array
    {
        if (! Schema::hasTable('perf_request_stats')) {
            return ['available' => false];
        }
        $since = now()->subHours($hours)->startOfHour();
        $rows = DB::table('perf_request_stats')->where('bucket', '>=', $since)->get();
        $h = fn ($r) => [$r->h0, $r->h1, $r->h2, $r->h3, $r->h4, $r->h5, $r->h6, $r->h7, $r->h8, $r->h9];
        $kinds = [];
        $mods = [];
        $add = function (array &$into, string $key, $r) use ($h) {
            $t = $into[$key] ?? ['n' => 0, 'sum_ms' => 0, 'max_ms' => 0, 'errors' => 0, 'queries' => 0, 'h' => array_fill(0, 10, 0)];
            $t['n'] += $r->n;
            $t['sum_ms'] += $r->sum_ms;
            $t['max_ms'] = max($t['max_ms'], $r->max_ms);
            $t['errors'] += $r->errors;
            $t['queries'] += $r->sum_queries;
            foreach ($h($r) as $b => $c) {
                $t['h'][$b] += $c;
            }
            $into[$key] = $t;
        };
        foreach ($rows as $r) {
            $add($kinds, $r->kind, $r);
            $add($mods, $r->kind . '|' . $r->module, $r);
        }
        $fig = fn (?array $t) => $t ? ['n' => (int) $t['n'], 'avg' => (int) round($t['sum_ms'] / max(1, $t['n'])), 'p50' => self::percentile($t['h'], 50), 'p95' => self::percentile($t['h'], 95), 'p99' => self::percentile($t['h'], 99),
            'max' => (int) $t['max_ms'], 'errors' => (int) $t['errors'], 'queries_avg' => round($t['queries'] / max(1, $t['n']), 1)] : ['n' => 0];
        $worst = [];
        foreach ($mods as $key => $t) {
            [$kind, $module] = explode('|', $key, 2);
            if ($kind === 'page' && $t['n'] >= 5) {
                $worst[] = ['module' => $module] + $fig($t);
            }
        }
        usort($worst, fn ($a, $b) => ($b['p95'] ?? 0) <=> ($a['p95'] ?? 0));

        return ['available' => true, 'hours' => $hours, 'pages' => $fig($kinds['page'] ?? null), 'api' => $fig($kinds['api'] ?? null), 'worst' => array_slice($worst, 0, 5), 'measured_at' => now()->toIso8601String(),
            'since' => optional($rows->min('bucket') ? \Carbon\Carbon::parse($rows->min('bucket')) : null)->toIso8601String()];
    }
}
