<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Opens the list pages of WIMS one after another as a Super Admin (the way the browser asks for them) and reports, for each, the time, the number of database
 * queries, the time spent in the database and any query that repeats many times (the signature of a missing eager load). READ ONLY: every request runs inside a
 * transaction that is rolled back, only GET pages are asked, and addresses that can change things (tracking, presence, downloads, logout) are skipped.
 * Used to decide what to fix first (Settings > Performance & Data keeps the same facts for the live site).
 */
class ProfilePages extends Command
{
    protected $signature = 'wims:profile-pages {--user=1 : Super Admin user id to act as} {--only= : only routes whose name contains this text} {--limit=30 : how many of the slowest to list} {--json= : also write the full result to this file} {--trace : for each page also print outgoing web calls and the longest gaps between queries (where the time goes outside the database)}';

    protected $description = 'Time every list page (read only) and show the slowest, with query counts and repeated queries';

    private const SKIP = ['track', 'heartbeat', 'presence', 'logout', 'download', 'print', 'pdf', 'export', 'scan', 'sync', 'health', 'avatar', 'image', 'photo', 'qr', 'stream', 'poll', 'impersonat', 'backup', 'verify', 'login', 'password', 'two-factor', 'ziggy', 'media', 'file', 'wimschat', 'bootstrap', 'widget', 'capture', 'camera', 'logs.', 'invoice', 'statement', 'delivery-note', 'gate-pass', 'executive-report', 'balance', 'weather', 'aris', 'ai-', 'drive', 'gateways', 'wims-connect.send', 'face', 'ocr'];  // the last ones call outside services (AI, SMS, weather, Drive): never from a profiling run

    public function handle(): int
    {
        $user = User::find((int) $this->option('user'));
        if (! $user || ! $user->hasRole('Super Admin')) {
            $this->error('Pick a Super Admin user id (--user=).');

            return self::FAILURE;
        }
        $only = (string) $this->option('only');
        $routes = collect(Route::getRoutes()->getRoutes())->filter(function ($r) use ($only) {
            $name = (string) $r->getName();
            if (! in_array('GET', $r->methods(), true) || ! $name || str_contains($r->uri(), '{') || str_starts_with($r->uri(), 'api/')) {
                return false;
            }
            foreach (self::SKIP as $s) {
                if (str_contains($name, $s) || str_contains($r->uri(), $s)) {
                    return false;
                }
            }

            return ! $only || str_contains($name, $only);
        })->values();

        $kernel = app(HttpKernel::class);
        $rows = [];
        foreach ($routes as $i => $route) {
            $uri = '/' . ltrim($route->uri(), '/');
            Auth::guard('web')->setUser($user);
            Auth::shouldUse('web');
            $queries = [];
            $dbMs = 0.0;
            DB::flushQueryLog();
            DB::enableQueryLog();
            $stamps = [];
            if ($this->option('trace')) {
                DB::listen(function ($q) use (&$stamps) {
                    $stamps[] = [microtime(true), $q->time, substr(preg_replace('/\s+/', ' ', $q->sql), 0, 110)];
                });
                \Illuminate\Support\Facades\Event::listen(\Illuminate\Http\Client\Events\ResponseReceived::class, function ($e) {
                    $this->line('   web call: ' . $e->request->method() . ' ' . substr($e->request->url(), 0, 100) . ' -> ' . $e->response->status());
                });
            }
            DB::beginTransaction();
            $t0 = microtime(true);
            $status = 0;
            $err = null;
            try {
                $request = Request::create($uri, 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA_VERSION' => (string) (new \App\Http\Middleware\HandleInertiaRequests())->version(Request::create('/'))]);
                $request->setUserResolver(fn () => $user);
                $response = $kernel->handle($request);
                $status = $response->getStatusCode();
            } catch (\Throwable $e) {
                $err = substr($e->getMessage(), 0, 90);
            }
            $ms = (microtime(true) - $t0) * 1000;
            $log = DB::getQueryLog();
            DB::disableQueryLog();
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->option('trace') && $stamps) {
                $prev = $t0;
                $gaps = [];
                foreach ($stamps as [$at, $took, $sql]) {
                    $gaps[] = [($at - $prev) * 1000 - $took, $sql];
                    $prev = $at;
                }
                $gaps[] = [(microtime(true) - $prev) * 1000, '(after the last query)'];
                usort($gaps, fn ($a, $b) => $b[0] <=> $a[0]);
                foreach (array_slice($gaps, 0, 4) as [$g, $sql]) {
                    $this->line(sprintf('   gap before: %6.0f ms  %s', $g, $sql));
                }
                $byTime = $stamps;
                usort($byTime, fn ($a, $b) => $b[1] <=> $a[1]);
                foreach (array_slice($byTime, 0, 3) as [, $took, $sql]) {
                    $this->line(sprintf('   slowest query: %6.1f ms  %s', $took, $sql));
                }
            }
            $shapes = [];
            foreach ($log as $q) {
                $dbMs += $q['time'];
                $shape = preg_replace(['/\s+/', "/'[^']*'/", '/\b\d+(\.\d+)?\b/', '/\(\?(,\s*\?)*\)/'], [' ', '?', '?', '(?)'], $q['query']);
                $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
            }
            arsort($shapes);
            $topShape = key($shapes);
            $rows[] = [
                'route' => $route->getName(), 'uri' => $uri, 'status' => $status ?: ($err ? 'ERR' : '-'), 'ms' => (int) round($ms), 'queries' => count($log),
                'db_ms' => (int) round($dbMs), 'repeat' => $shapes ? reset($shapes) : 0, 'repeat_sql' => $shapes && reset($shapes) >= 8 ? substr((string) $topShape, 0, 200) : '', 'error' => $err,
            ];
            if (($i + 1) % 25 === 0) {
                $this->line('... ' . ($i + 1) . ' / ' . $routes->count());
            }
        }

        usort($rows, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $limit = max(1, (int) $this->option('limit'));
        $this->info(count($rows) . ' pages profiled. Slowest ' . $limit . ':');
        $this->table(['Route', 'Status', 'ms', 'Queries', 'DB ms', 'Same query x'], array_map(fn ($r) => [$r['route'], $r['status'], $r['ms'], $r['queries'], $r['db_ms'], $r['repeat']], array_slice($rows, 0, $limit)));

        $many = collect($rows)->filter(fn ($r) => $r['repeat'] >= 8)->sortByDesc('repeat')->take(15);
        if ($many->isNotEmpty()) {
            $this->newLine();
            $this->info('Pages that ask the same query many times (probable missing eager load):');
            foreach ($many as $r) {
                $this->line(sprintf('  %-40s x%-4d %s', $r['route'], $r['repeat'], $r['repeat_sql']));
            }
        }
        $bad = collect($rows)->filter(fn ($r) => $r['error'] || (is_int($r['status']) && $r['status'] >= 500));
        if ($bad->isNotEmpty()) {
            $this->newLine();
            $this->warn('Pages that failed:');
            foreach ($bad as $r) {
                $this->line('  ' . $r['route'] . '  ' . $r['status'] . '  ' . $r['error']);
            }
        }
        if ($file = $this->option('json')) {
            file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
