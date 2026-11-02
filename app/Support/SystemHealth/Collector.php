<?php

namespace App\Support\SystemHealth;

use App\Models\User;
use App\Support\Performance\QueueMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The facts behind the System Health page. Every figure is MEASURED or READ here (nothing is invented); a figure that cannot be read comes back as null and the
 * page says "not available" for it. Each probe is isolated: one failing never breaks the others or the page. Heavy probes (the size of the database and of the
 * files) are remembered for a few minutes; the two that call outside services (the SMS balance and the cloud drive) are asked for separately (part "cloud") so
 * the page opens at once. READ ONLY: nothing here writes business data.
 */
class Collector
{
    /** The fast part: this server, the database, the files, the people, the jobs. */
    public static function core(User $user): array
    {
        $t0 = microtime(true);
        $out = [
            'generated_at' => now()->toIso8601String(),
            'server' => self::probe(fn () => self::server()),
            'speed' => self::probe(fn () => self::speed()),
            'database' => self::probe(fn () => self::database()),
            'disk' => self::probe(fn () => self::disk()),
            'people' => self::probe(fn () => self::people($user)),
            'jobs' => self::probe(fn () => self::jobs()),
            'backups' => self::probe(fn () => self::backups()),
            'logs' => self::probe(fn () => self::logs()),
            'slow' => self::probe(fn () => self::slow()),
            'licence' => self::probe(fn () => self::licence()),
            'sms_usage' => self::probe(fn () => self::smsUsage()),
            'you' => self::probe(fn () => self::you($user)),
        ];
        $out['build_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $out['checks'] = self::checks($out);
        $out['score'] = self::score($out['checks']);
        $out['sections'] = self::sections($out);

        return $out;
    }

    /** The slow part: asks the SMS provider for its balance and the cloud drive for its space (each answer is remembered briefly). */
    public static function cloud(): array
    {
        $sms = self::probe(fn () => self::smsBalance());
        $drive = self::probe(fn () => self::drive());

        return [
            'generated_at' => now()->toIso8601String(), 'sms' => $sms, 'drive' => $drive,
            'sections' => ['sms' => self::smsSection($sms), 'drive' => self::driveSection($drive)],
        ];
    }

    /** The five words of a health rating, from a 0-100 score. */
    public static function rate(int $score): string
    {
        return $score >= 92 ? 'excellent' : ($score >= 80 ? 'very_good' : ($score >= 65 ? 'good' : ($score >= 45 ? 'fair' : 'bad')));
    }

    private static function sec(?int $score, array $actions = [], string $note = ''): array
    {
        return ['score' => $score, 'rating' => $score === null ? 'unknown' : self::rate($score), 'note' => $note, 'actions' => $actions];
    }

    private static function band(float $v, array $cuts, array $scores): int
    {
        foreach ($cuts as $i => $c) {
            if ($v < $c) {
                return $scores[$i];
            }
        }

        return end($scores);
    }

    /** A little speed test on demand: the time of a database question, a cache write and read, and a file write, repeated and averaged. */
    public static function speedTest(): array
    {
        Cache::forget('system-health.speed');

        return self::speed(true);
    }

    private static function probe(callable $fn): ?array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ['error' => true, 'message' => substr($e->getMessage(), 0, 120)];
        }
    }

    // ---------------------------------------------------------------------------------------------------------------- this server

    private static function server(): array
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        $cores = null;
        if (is_readable('/proc/cpuinfo')) {
            $cores = max(1, substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor'));
        }
        $limit = self::bytes((string) ini_get('memory_limit'));

        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'os' => PHP_OS_FAMILY,
            'memory_limit_mb' => $limit > 0 ? (int) round($limit / 1048576) : null,
            'memory_now_mb' => (int) round(memory_get_usage(true) / 1048576),
            'memory_peak_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
            'load' => $load ? array_map(fn ($v) => round($v, 2), $load) : null,
            'cores' => $cores,
            'opcache' => function_exists('opcache_get_status') && ($s = @opcache_get_status(false)) ? ['on' => (bool) ($s['opcache_enabled'] ?? false), 'hit_rate' => isset($s['opcache_statistics']['opcache_hit_rate']) ? round($s['opcache_statistics']['opcache_hit_rate'], 1) : null] : ['on' => false, 'hit_rate' => null],
            'timezone' => config('app.timezone'),
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
            'debug' => (bool) config('app.debug'),
        ];
    }

    /** How fast this system answers: remembered for 20 seconds so opening the page twice does not measure twice. */
    private static function speed(bool $fresh = false): array
    {
        $key = 'system-health.speed';
        if (! $fresh && ($c = Cache::get($key))) {
            return $c;
        }
        $db = [];
        for ($i = 0; $i < 6; $i++) {
            $t = microtime(true);
            DB::select('select 1');
            $db[] = (microtime(true) - $t) * 1000;
        }
        $cache = [];
        for ($i = 0; $i < 4; $i++) {
            $t = microtime(true);
            Cache::put('system-health.probe', $i, 30);
            Cache::get('system-health.probe');
            $cache[] = (microtime(true) - $t) * 1000;
        }
        $disk = [];
        $file = storage_path('framework/health-probe.tmp');
        for ($i = 0; $i < 3; $i++) {
            $t = microtime(true);
            @file_put_contents($file, str_repeat('x', 4096));
            @file_get_contents($file);
            $disk[] = (microtime(true) - $t) * 1000;
        }
        @unlink($file);
        // the page itself: how long since this request started
        $start = defined('LARAVEL_START') ? LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $sorted = fn (array $a) => (function () use ($a) { sort($a); return round($a[(int) floor(count($a) / 2)], 1); })();
        $result = [
            'db_ms' => $sorted($db), 'cache_ms' => $sorted($cache), 'disk_ms' => $sorted($disk),
            'request_ms' => (int) round((microtime(true) - $start) * 1000), 'measured_at' => now()->toIso8601String(),
        ];
        Cache::put($key, $result, 20);

        return $result;
    }

    // ---------------------------------------------------------------------------------------------------------------- database and files

    private static function database(): array
    {
        return Cache::remember('system-health.database', 300, function () {
            $schema = DB::getDatabaseName();
            $rows = DB::select('select table_name as t, data_length as d, index_length as i, table_rows as r from information_schema.tables where table_schema = ?', [$schema]);
            $data = $index = 0;
            $top = [];
            foreach ($rows as $r) {
                $data += (int) $r->d;
                $index += (int) $r->i;
                $top[] = ['name' => $r->t, 'mb' => round(((int) $r->d + (int) $r->i) / 1048576, 1), 'rows' => (int) $r->r];
            }
            usort($top, fn ($a, $b) => $b['mb'] <=> $a['mb']);
            $quota = (int) (get_settings('system_health_db_quota_mb') ?: 0);

            return [
                'size_mb' => round(($data + $index) / 1048576, 1), 'data_mb' => round($data / 1048576, 1), 'index_mb' => round($index / 1048576, 1),
                'tables' => count($rows), 'top' => array_slice($top, 0, 6), 'quota_mb' => $quota > 0 ? $quota : null, 'measured_at' => now()->toIso8601String(),
            ];
        });
    }

    private static function disk(): array
    {
        return Cache::remember('system-health.disk', 1800, function () {
            $path = base_path();
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            $dirs = ['uploads' => public_path('uploads'), 'documents' => storage_path('app'), 'logs' => storage_path('logs')];
            $sizes = [];
            foreach ($dirs as $label => $dir) {
                $sizes[$label] = self::dirMb($dir, 2.5);
            }
            $quota = (int) (get_settings('system_health_disk_quota_mb') ?: 0);

            return [
                'free_gb' => $free !== false ? round($free / 1073741824, 1) : null, 'total_gb' => $total !== false ? round($total / 1073741824, 1) : null,
                'sizes_mb' => $sizes, 'quota_mb' => $quota > 0 ? $quota : null, 'measured_at' => now()->toIso8601String(),
            ];
        });
    }

    /** Size of a folder in MB, stopping after $seconds (the figure is then marked partial) so a huge folder can never slow the page. */
    private static function dirMb(string $dir, float $seconds): ?array
    {
        if (! is_dir($dir)) {
            return null;
        }
        $t0 = microtime(true);
        $bytes = 0;
        $partial = false;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $bytes += $f->getSize();
                }
                if ((microtime(true) - $t0) > $seconds) {
                    $partial = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return ['mb' => round($bytes / 1048576, 1), 'partial' => $partial];
    }

    // ---------------------------------------------------------------------------------------------------------------- people, jobs, backups, logs

    private static function people(User $user): array
    {
        $account = (int) $user->account_id;
        $online = DB::table('sessions')->where('last_activity', '>=', now()->subMinutes(5)->timestamp)->whereNotNull('user_id')->distinct()->count('user_id');
        $today = now()->startOfDay();
        $ok = $bad = null;
        if (Schema::hasTable('login_logs')) {
            $base = DB::table('login_logs')->where('account_id', $account)->where('attempted_at', '>=', $today)->whereNull('deleted_at');
            $ok = (clone $base)->where('status', 'success')->count();
            $bad = (clone $base)->where('status', '!=', 'success')->count();
        }

        return ['online_now' => $online, 'logins_today' => $ok, 'failed_logins_today' => $bad, 'active_users' => User::query()->where('is_active', true)->count()];
    }

    private static function jobs(): array
    {
        $beat = QueueMode::lastBeat();
        $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $notif = Schema::hasTable('notification_queue') ? DB::table('notification_queue')->count() : null;

        return [
            'scheduler_last_beat_at' => $beat ? date('c', $beat) : null, 'scheduler_minutes_ago' => $beat ? (int) floor((time() - $beat) / 60) : null,
            'pending' => $pending, 'failed' => $failed, 'notification_queue' => $notif,
        ];
    }

    private static function backups(): array
    {
        $last = Schema::hasTable('backup_activities') ? DB::table('backup_activities')->where('activity', 'like', '%backup%')->orderByDesc('id')->first() : null;
        $at = $last ? \Carbon\Carbon::parse($last->created_at) : null;
        $auto = \App\Support\Backup\AutoBackupSettings::class;

        return [
            'last_at' => $at ? $at->toIso8601String() : null, 'hours_ago' => $at ? (int) floor($at->diffInHours(now())) : null,
            'auto_enabled' => $auto::enabled(), 'next_due_at' => optional($auto::nextDueAt())->toIso8601String(),
        ];
    }

    private static function logs(): array
    {
        $file = storage_path('logs/laravel-' . now()->format('Y-m-d') . '.log');
        if (! is_file($file)) {
            $file = storage_path('logs/laravel.log');
        }
        $errors = null;
        $size = null;
        if (is_file($file)) {
            $size = round(filesize($file) / 1048576, 1);
            // only the end of a big file is read (the last 2 MB), so this stays quick; today's errors are counted there
            $fh = @fopen($file, 'r');
            if ($fh) {
                $len = filesize($file);
                fseek($fh, max(0, $len - 2097152));
                $chunk = (string) fread($fh, 2097152);
                fclose($fh);
                $errors = preg_match_all('/^\[' . preg_quote(now()->format('Y-m-d'), '/') . '[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY)/m', $chunk);
            }
        }

        return ['errors_today' => $errors, 'log_mb' => $size];
    }

    private static function slow(): array
    {
        if (! Schema::hasTable('slow_logs')) {
            return ['pages_24h' => null, 'queries_24h' => null, 'worst' => []];
        }
        $since = now()->subDay();
        $q = DB::table('slow_logs')->where('created_at', '>=', $since);
        $kinds = (clone $q)->selectRaw('kind, count(*) c')->groupBy('kind')->pluck('c', 'kind');
        $worst = (clone $q)->where('kind', 'request')->orderByDesc('duration_ms')->limit(3)->get(['label', 'duration_ms'])->map(fn ($r) => ['label' => substr((string) $r->label, 0, 60), 'ms' => (int) $r->duration_ms])->all();

        return ['pages_24h' => (int) ($kinds['request'] ?? 0), 'queries_24h' => (int) ($kinds['query'] ?? 0), 'worst' => $worst];
    }

    private static function licence(): ?array
    {
        $p = app()->bound('licence.status') ? app('licence.status') : null;
        if (is_array($p)) {
            return ['label' => $p['label'] ?? null, 'days_left' => $p['days_left'] ?? null];
        }
        if (! Schema::hasTable('licences')) {
            return null;
        }
        $row = DB::table('licences')->orderByDesc('id')->first();
        if (! $row) {
            return null;
        }
        $exp = $row->expires_at ?? ($row->expiry_date ?? null);

        return ['label' => $row->status ?? null, 'days_left' => $exp ? (int) now()->diffInDays(\Carbon\Carbon::parse($exp), false) : null];
    }

    private static function smsUsage(): array
    {
        if (! Schema::hasTable('wims_connect_logs')) {
            return ['sent_month' => null, 'failed_month' => null, 'last_at' => null];
        }
        $from = now()->startOfMonth();
        $base = DB::table('wims_connect_logs')->where('channel', 'sms')->where('created_at', '>=', $from);
        $sent = (clone $base)->whereIn('status', ['sent', 'delivered'])->count();
        $failed = (clone $base)->whereIn('status', ['failed', 'error'])->count();
        $last = DB::table('wims_connect_logs')->where('channel', 'sms')->whereIn('status', ['sent', 'delivered'])->max('sent_at');

        return ['sent_month' => $sent, 'failed_month' => $failed, 'last_at' => $last ? \Carbon\Carbon::parse($last)->toIso8601String() : null];
    }

    // ---------------------------------------------------------------------------------------------------------------- the person looking, and where they are

    private static function you(User $user): array
    {
        $scope = function_exists('warehouse_scope_ids') ? warehouse_scope_ids() : null;
        $names = [];
        $count = null;
        if (is_array($scope)) {
            $count = count($scope);
            $names = \App\Models\Warehouse::withoutGlobalScopes()->whereIn('id', array_slice($scope, 0, 6))->pluck('name')->all();
        }
        $unread = method_exists($user, 'unreadNotifications') ? $user->unreadNotifications()->count() : null;
        $pendingTransfers = $user->can('approve-transfers')
            ? \App\Models\Transfer::query()->where('approval_status', 'pending')->when(is_array($scope), fn ($q) => $q->where(fn ($w) => $w->whereIn('from_warehouse_id', $scope)->orWhereIn('to_warehouse_id', $scope)))->count()
            : null;
        $last = Schema::hasTable('login_logs') ? DB::table('login_logs')->where('user_id', $user->id)->where('status', 'success')->whereNull('deleted_at')->orderByDesc('attempted_at')->skip(1)->first(['attempted_at', 'ip_address', 'device', 'browser']) : null;
        $idle = \App\Support\IdleTimeout::minutesFor($user, false);

        return [
            'name' => $user->name, 'roles' => $user->getRoleNames()->values()->all(), 'ip' => request()->ip(),
            'scope_all' => $scope === null, 'scope_count' => $count, 'scope_names' => $names,
            'unread_notifications' => $unread, 'pending_transfers' => $pendingTransfers,
            'previous_login_at' => $last ? \Carbon\Carbon::parse($last->attempted_at)->toIso8601String() : null, 'previous_login_device' => $last ? trim(($last->device ?? '') . ' ' . ($last->browser ?? '')) : null,
            'idle_limit_minutes' => $idle,
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------- the two outside calls

    private static function smsBalance(): array
    {
        $gateway = \App\Models\WimsConnectGateway::query()->where('driver', 'sms')->first();
        $config = $gateway->config ?? [];
        if (! str_contains(strtolower((string) ($config['provider'] ?? '')), 'beem')) {
            return ['supported' => false];
        }
        // the same remembered answer the SMS settings page uses (45 seconds), so the provider is never asked twice
        $r = Cache::remember('wims-connect.beem-balance', 45, fn () => (new \App\Services\WimsConnect\Drivers\Sms\BeemSmsProvider())->balance($config) + ['checked_at' => now()->toIso8601String()]);
        if (empty($r['ok'])) {
            Cache::forget('wims-connect.beem-balance');

            return ['supported' => true, 'provider' => 'Beem Africa'] + $r;
        }
        // the percentage is the credit left compared with the most credit seen on this account (or a full level set in `system_health_sms_full_credit`)
        $balance = (float) ($r['balance'] ?? 0);
        $set = (float) (get_settings('system_health_sms_full_credit') ?: 0);
        $peak = max($set, (float) Cache::get('system-health.sms-peak', 0), $balance);
        if ($set <= 0 && $peak > (float) Cache::get('system-health.sms-peak', 0)) {
            Cache::forever('system-health.sms-peak', $peak);
        }

        return ['supported' => true, 'provider' => 'Beem Africa', 'percent' => $peak > 0 ? (int) round(min(100, $balance / $peak * 100)) : 0, 'full_credit' => (int) round($peak), 'full_credit_set' => $set > 0] + $r;
    }

    private static function drive(): array
    {
        $client = app(\App\Services\SiteDiary\GoogleDriveClient::class);
        if (! $client->ready()) {
            return ['connected' => false, 'provider' => 'Google Drive'];
        }
        $q = Cache::remember('system-health.drive', 300, fn () => $client->quota());

        return ['connected' => true, 'provider' => 'Google Drive', 'limit_gb' => $q['limit'] !== null ? round($q['limit'] / 1073741824, 1) : null, 'used_gb' => round($q['used'] / 1073741824, 2), 'left_gb' => $q['left'] !== null ? round($q['left'] / 1073741824, 1) : null, 'trash_gb' => round(($q['trash'] ?? 0) / 1073741824, 2)];
    }

    // ---------------------------------------------------------------------------------------------------------------- one rating and what to do, per section

    /** For each part of the system: a 0-100 score, the rating word (excellent, very_good, good, fair, bad, unknown) and what can be done about it. */
    private static function sections(array $d): array
    {
        $ok = fn ($x) => is_array($x) && empty($x['error']);
        $out = [];
        $opt = ['text' => __('Run Optimize All'), 'action' => 'optimize'];

        // speed of the server
        if ($ok($d['speed'] ?? null)) {
            $s = $d['speed'];
            $score = min(self::band($s['db_ms'], [3, 8, 20, 40], [98, 88, 70, 50, 25]), self::band($s['cache_ms'], [2, 5, 12, 25], [98, 88, 70, 50, 25]), self::band($s['disk_ms'], [2, 5, 12, 25], [98, 88, 70, 50, 25]));
            $act = [];
            if ($score < 80) {
                $act[] = $opt;
                $act[] = ['text' => __('If it stays slow, ask the host for a newer PHP version and to switch OPcache on.')];
            }
            $out['speed'] = self::sec($score, $act);
        }

        // the system itself: memory and busyness
        if ($ok($d['server'] ?? null)) {
            $v = $d['server'];
            $mem = $v['memory_limit_mb'] ? $v['memory_now_mb'] / $v['memory_limit_mb'] * 100 : 40;
            $score = self::band($mem, [40, 60, 75, 90], [98, 88, 70, 48, 25]);
            $act = [];
            if ($v['load'] && $v['cores']) {
                $score = min($score, self::band($v['load'][0] / $v['cores'] * 100, [40, 70, 100, 150], [98, 88, 70, 48, 25]));
            }
            if (! ($v['opcache']['on'] ?? false)) {
                $score = min($score, 79);
                $act[] = ['text' => __('Ask the host (cPanel > Select PHP Version > Options) to switch OPcache on: pages open faster.')];
            }
            if ($v['debug']) {
                $score = min($score, 40);
                $act[] = ['text' => __('Switch debug mode off (APP_DEBUG=false) on the live site.')];
            }
            if ($score < 80 && $mem > 70) {
                $act[] = ['text' => __('Memory is running high: raise the PHP memory limit in cPanel, or ask people to avoid very large reports at the same time.')];
            }
            $out['system'] = self::sec($score, $act);
        }

        // database
        if ($ok($d['database'] ?? null)) {
            $x = $d['database'];
            $act = [];
            if ($x['quota_mb']) {
                $score = self::band($x['size_mb'] / $x['quota_mb'] * 100, [50, 70, 85, 95], [98, 88, 68, 45, 20]);
                $note = '';
            } else {
                $score = self::band($x['size_mb'], [250, 600, 1200, 2500], [98, 88, 68, 48, 25]);
                $note = __('No database space limit is set, so this is judged by size alone.');
            }
            if ($score < 92 && ! empty($x['top'][0])) {
                $act[] = ['text' => __('The biggest table is :t (:mb MB). Optimize All and the nightly clean-up keep old history from growing.', ['t' => $x['top'][0]['name'], 'mb' => $x['top'][0]['mb']])];
                $act[] = $opt;
            }
            if ($score < 65) {
                $act[] = ['text' => __('Open Settings > Performance & Data and shorten how long history is kept.'), 'route' => 'performance.index'];
            }
            $out['database'] = self::sec($score, $act, $note);
        }

        // server disk
        if ($ok($d['disk'] ?? null) && ($d['disk']['total_gb'] ?? null)) {
            $k = $d['disk'];
            $usedP = ($k['total_gb'] - $k['free_gb']) / $k['total_gb'] * 100;
            $score = self::band($usedP, [60, 75, 85, 92], [98, 88, 68, 45, 20]);
            $act = $score < 80 ? [$opt, ['text' => __('Delete old files in Archive and Site Diary that are no longer needed, or ask the host for more disk space.')]] : [];
            $out['disk'] = self::sec($score, $act, __('A shared host may give your account less than the whole disk.'));
        }

        // backups and scheduled work
        if ($ok($d['backups'] ?? null) && $ok($d['jobs'] ?? null)) {
            $b = $d['backups'];
            $j = $d['jobs'];
            $act = [];
            $h = $b['hours_ago'];
            $score = $h === null ? 30 : self::band($h, [26, 50, 96, 168], [98, 88, 68, 45, 20]);
            if ($score < 80) {
                $act[] = ['text' => $h === null ? __('No backup is recorded yet: make one now.') : __('The last backup is old: make a new one now.'), 'route' => 'backup.index'];
            }
            if (! $b['auto_enabled']) {
                $act[] = ['text' => __('Switch automatic backups on.'), 'route' => 'backup.index'];
            }
            $m = $j['scheduler_minutes_ago'];
            if ($m === null) {
                $score = min($score, 60);
                $act[] = ['text' => __('No heartbeat from the scheduler yet. In cPanel > Cron Jobs the line `* * * * * php artisan schedule:run` must exist.')];
            } elseif ($m > 5) {
                $score = min($score, $m > 15 ? 25 : 55);
                $act[] = ['text' => __('The scheduler has stopped beating. Check the cron job in cPanel.')];
            }
            if (($j['failed'] ?? 0) > 0) {
                $score = max(0, $score - 12);
                $act[] = ['text' => __(':n background job(s) failed. Ask your administrator to look at them.', ['n' => $j['failed']])];
            }
            $out['backups'] = self::sec($score, $act);
        }

        // people, errors and safety
        if ($ok($d['logs'] ?? null) || $ok($d['people'] ?? null)) {
            $e = $ok($d['logs'] ?? null) ? ($d['logs']['errors_today'] ?? null) : null;
            $score = $e === null ? 90 : self::band($e, [1, 5, 20, 100], [98, 88, 68, 45, 20]);
            $act = [];
            if ($e !== null && $e > 0) {
                $act[] = ['text' => __(':n error(s) were written to the log today: send the log of today to the developer.', ['n' => $e])];
            }
            if ($ok($d['people'] ?? null) && ($d['people']['failed_logins_today'] ?? 0) >= 10) {
                $score = max(0, $score - 15);
                $act[] = ['text' => __('Many failed sign-ins today: look at the Login Logs for an address that keeps trying.'), 'route' => 'login-logs.index'];
            }
            if ($ok($d['slow'] ?? null) && ($d['slow']['pages_24h'] ?? 0) >= 5) {
                $score = max(0, $score - 10);
                $act[] = ['text' => __('Some pages were slow in the last 24 hours: the list is in Settings > Performance & Data.'), 'route' => 'performance.index'];
            }
            $out['safety'] = self::sec($score, $act);
        }

        // licence
        if ($ok($d['licence'] ?? null) && isset($d['licence']['days_left'])) {
            $days = (int) $d['licence']['days_left'];
            $score = self::band($days, [8, 15, 31, 91], [20, 45, 65, 85, 98]);
            $out['licence'] = self::sec($score, $score < 85 ? [['text' => __('Renew the licence before it ends.'), 'route' => 'wims-licence.show']] : []);
        }

        return $out;
    }

    private static function smsSection(?array $sms): array
    {
        if (! is_array($sms) || ! empty($sms['error']) || empty($sms['supported']) || empty($sms['ok'])) {
            return self::sec(null, [], __('The credit could not be read.'));
        }
        $p = (int) ($sms['percent'] ?? 0);
        $score = self::band($p, [8, 20, 40, 65], [20, 45, 68, 85, 98]);
        $act = $score < 80 ? [['text' => __('Top up SMS credit with Beem Africa before alerts stop going out.')]] : [];

        return self::sec($score, $act, ! empty($sms['full_credit_set']) ? __('Compared with the full level set for this system.') : __('Compared with the most credit this system has seen.'));
    }

    private static function driveSection(?array $drive): array
    {
        if (! is_array($drive) || ! empty($drive['error']) || empty($drive['connected'])) {
            return self::sec(null, [], __('Google Drive is not connected.'));
        }
        if (empty($drive['limit_gb'])) {
            return self::sec(98);
        }
        $p = $drive['used_gb'] / $drive['limit_gb'] * 100;
        $score = self::band($p, [50, 70, 85, 95], [98, 88, 68, 45, 20]);

        return self::sec($score, $score < 80 ? [['text' => __('Empty the drive bin, or remove old Site Diary files from Google Drive.'), 'route' => 'site-diary.settings']] : []);
    }

    // ---------------------------------------------------------------------------------------------------------------- the verdict

    /** Each check: ['key', 'label', 'state' => ok|warn|bad|unknown, 'text']. States come from plain thresholds written here, not from guesses. */
    private static function checks(array $d): array
    {
        $c = [];
        $add = function (string $key, string $label, string $state, string $text) use (&$c) {
            $c[] = ['key' => $key, 'label' => $label, 'state' => $state, 'text' => $text];
        };
        $sp = $d['speed'] ?? null;
        if ($sp && empty($sp['error'])) {
            $db = $sp['db_ms'];
            $add('db_speed', __('Database speed'), $db < 8 ? 'ok' : ($db < 40 ? 'warn' : 'bad'), __('A database question takes :ms ms.', ['ms' => $db]));
        }
        $dbs = $d['database'] ?? null;
        if ($dbs && empty($dbs['error']) && $dbs['quota_mb']) {
            $p = $dbs['size_mb'] / $dbs['quota_mb'] * 100;
            $add('db_space', __('Database space'), $p < 70 ? 'ok' : ($p < 90 ? 'warn' : 'bad'), __(':p% of the database space is used.', ['p' => round($p)]));
        }
        $dk = $d['disk'] ?? null;
        if ($dk && empty($dk['error']) && $dk['total_gb']) {
            $usedP = ($dk['total_gb'] - $dk['free_gb']) / $dk['total_gb'] * 100;
            $add('disk_space', __('Server disk'), $usedP < 80 ? 'ok' : ($usedP < 92 ? 'warn' : 'bad'), __(':p% of the server disk is used.', ['p' => round($usedP)]));
        }
        $j = $d['jobs'] ?? null;
        if ($j && empty($j['error'])) {
            if ($j['scheduler_minutes_ago'] === null) {
                $add('scheduler', __('Scheduled tasks'), 'unknown', __('No sign yet that the scheduler (cron) is running.'));
            } else {
                $m = $j['scheduler_minutes_ago'];
                $add('scheduler', __('Scheduled tasks'), $m <= 3 ? 'ok' : ($m <= 15 ? 'warn' : 'bad'), __('The scheduler last reported :m minute(s) ago.', ['m' => $m]));
            }
            if (($j['failed'] ?? 0) > 0) {
                $add('failed_jobs', __('Failed jobs'), 'warn', __(':n background job(s) failed.', ['n' => $j['failed']]));
            }
        }
        $b = $d['backups'] ?? null;
        if ($b && empty($b['error'])) {
            if ($b['hours_ago'] === null) {
                $add('backup', __('Backup'), 'warn', __('No backup is recorded yet.'));
            } else {
                $add('backup', __('Backup'), $b['hours_ago'] <= 48 ? 'ok' : ($b['hours_ago'] <= 24 * 7 ? 'warn' : 'bad'), __('The last backup was :h hour(s) ago.', ['h' => $b['hours_ago']]));
            }
        }
        $l = $d['logs'] ?? null;
        if ($l && empty($l['error']) && $l['errors_today'] !== null) {
            $add('errors', __('Errors today'), $l['errors_today'] === 0 ? 'ok' : ($l['errors_today'] < 20 ? 'warn' : 'bad'), __(':n error(s) written to the log today.', ['n' => $l['errors_today']]));
        }
        $s = $d['slow'] ?? null;
        if ($s && empty($s['error']) && $s['pages_24h'] !== null) {
            $add('slow_pages', __('Slow pages'), $s['pages_24h'] < 5 ? 'ok' : ($s['pages_24h'] < 40 ? 'warn' : 'bad'), __(':n slow page(s) in the last 24 hours.', ['n' => $s['pages_24h']]));
        }
        $p = $d['people'] ?? null;
        if ($p && empty($p['error']) && ($p['failed_logins_today'] ?? 0) >= 10) {
            $add('failed_logins', __('Sign-in attempts'), 'warn', __(':n failed sign-in(s) today.', ['n' => $p['failed_logins_today']]));
        }
        $li = $d['licence'] ?? null;
        if ($li && empty($li['error']) && isset($li['days_left'])) {
            $add('licence', __('Licence'), $li['days_left'] > 30 ? 'ok' : ($li['days_left'] > 7 ? 'warn' : 'bad'), __('The licence has :d day(s) left.', ['d' => $li['days_left']]));
        }
        $sv = $d['server'] ?? null;
        if ($sv && empty($sv['error']) && $sv['debug']) {
            $add('debug', __('Debug mode'), 'bad', __('Debug mode is ON. It must be off on the live site.'));
        }

        return $c;
    }

    private static function score(array $checks): array
    {
        $bad = count(array_filter($checks, fn ($c) => $c['state'] === 'bad'));
        $warn = count(array_filter($checks, fn ($c) => $c['state'] === 'warn'));
        $value = max(0, 100 - $bad * 22 - $warn * 8);

        return ['value' => $value, 'level' => $value >= 85 ? 'good' : ($value >= 60 ? 'fair' : 'poor'), 'bad' => $bad, 'warn' => $warn];
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;
        $u = strtolower(substr($v, -1));

        return match ($u) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }
}
