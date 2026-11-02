<?php

namespace App\Support\SystemHealth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The content of the System Health report that is e-mailed: for each part of the system its rating, every figure behind the rating, what can be done, and the
 * errors that belong to it (today's error messages grouped, failed jobs, slow pages and queries). Everything comes from the same Collector the page uses. Text from
 * logs is SCRUBBED before it leaves the server: e-mail addresses, passwords, keys and tokens, the values inside database queries, long numbers and server paths
 * are removed, so the report carries facts about the system and no personal data or secrets.
 */
class ReportBuilder
{
    /** @param array|null $connection the sender's own device measurement (percent, level, rtt, jitter, loss, type), validated by the controller */
    public static function build(User $user, ?array $connection = null): array
    {
        $core = Collector::core($user);
        $cloud = Collector::cloud();
        $sec = $core['sections'] ?? [];
        $ok = fn ($x) => is_array($x) && empty($x['error']);
        $c = [];

        // ---- the sender's connection (measured by their device, not by the server)
        if ($connection) {
            $score = (int) $connection['percent'];
            $c[] = self::part('connection', '📶', __('Sender\'s connection'), ['score' => $score, 'rating' => Collector::rate($score), 'actions' => $score < 65 ? [['text' => __('Move closer to the Wi-Fi router, or use a cable.')]] : [], 'note' => __('Measured by the sender\'s own device when the report was sent.')], [
                [__('Signal'), $score . '% (' . ($connection['level'] ?? '') . ')'], [__('Answer time'), ($connection['rtt'] ?? '--') . ' ms'], [__('Jumpiness'), ($connection['jitter'] ?? '--') . ' ms'], [__('Requests lost'), ($connection['loss'] ?? 0) . '%'], [__('Link'), $connection['type'] ?? '--'],
            ]);
        }

        // ---- speed of the server
        if ($ok($core['speed'] ?? null) && isset($sec['speed'])) {
            $s = $core['speed'];
            $c[] = self::part('speed', '⚡', __('Speed of the server'), $sec['speed'], [
                [__('Database answer'), $s['db_ms'] . ' ms'], [__('Cache answer'), $s['cache_ms'] . ' ms'], [__('Disk answer'), $s['disk_ms'] . ' ms'], [__('This report was assembled in'), $core['build_ms'] . ' ms'],
            ]);
        }

        // ---- the system
        if ($ok($core['server'] ?? null) && isset($sec['system'])) {
            $v = $core['server'];
            $rows = [
                [__('Memory in use'), $v['memory_now_mb'] . ' MB of ' . ($v['memory_limit_mb'] ?: '?') . ' MB (peak ' . $v['memory_peak_mb'] . ' MB)'],
                [__('Busy (1, 5, 15 minutes)'), $v['load'] ? implode(' / ', $v['load']) . ($v['cores'] ? ' on ' . $v['cores'] . ' cores' : '') : __('not reported by this host')],
                ['PHP / Laravel', $v['php'] . ' / ' . $v['laravel']], ['OPcache', $v['opcache']['on'] ? __('on') . ($v['opcache']['hit_rate'] !== null ? ' (' . $v['opcache']['hit_rate'] . '% hits)' : '') : __('off')],
                [__('Cache / queue'), $v['cache_driver'] . ' / ' . $v['queue_driver']], [__('Debug mode'), $v['debug'] ? __('ON (must be off)') : __('off')],
            ];
            $c[] = self::part('system', '🖥️', __('Speed of the system'), $sec['system'], $rows);
        }

        // ---- database
        if ($ok($core['database'] ?? null) && isset($sec['database'])) {
            $x = $core['database'];
            $rows = [[__('Total size'), self::mb($x['size_mb']) . ($x['quota_mb'] ? ' of ' . self::mb($x['quota_mb']) : ' (' . __('no limit set') . ')')], [__('Records'), self::mb($x['data_mb'])], [__('Search indexes'), self::mb($x['index_mb'])], [__('Tables'), (string) $x['tables']]];
            foreach ($x['top'] as $t) {
                $rows[] = [__('Largest') . ': ' . $t['name'], self::mb($t['mb']) . ' (' . number_format($t['rows']) . ' ' . __('rows') . ')'];
            }
            $c[] = self::part('database', '🗄️', __('Database space'), $sec['database'], $rows);
        }

        // ---- disk
        if ($ok($core['disk'] ?? null) && isset($sec['disk']) && ($core['disk']['total_gb'] ?? null)) {
            $k = $core['disk'];
            $rows = [[__('Free / whole disk'), $k['free_gb'] . ' GB / ' . $k['total_gb'] . ' GB'], [__('Used'), round(($k['total_gb'] - $k['free_gb']) / $k['total_gb'] * 100) . '%']];
            foreach ((array) $k['sizes_mb'] as $label => $v) {
                $rows[] = [['uploads' => __('Uploaded files'), 'documents' => __('Stored documents'), 'logs' => __('Log files')][$label] ?? $label, $v ? self::mb($v['mb']) . ($v['partial'] ? '+' : '') : '--'];
            }
            $c[] = self::part('disk', '💽', __('Server disk and files'), $sec['disk'], $rows);
        }

        // ---- SMS
        $sms = $cloud['sms'] ?? null;
        $rows = [];
        if ($ok($sms) && ! empty($sms['supported']) && ! empty($sms['ok'])) {
            $rows[] = [__('Credit left'), number_format((float) $sms['balance'])];
            $rows[] = [__('Battery'), ($sms['percent'] ?? 0) . '% ' . __('of') . ' ' . number_format((float) ($sms['full_credit'] ?? 0))];
        } else {
            $rows[] = [__('Credit left'), __('could not be read')];
        }
        if ($ok($core['sms_usage'] ?? null)) {
            $u = $core['sms_usage'];
            $rows[] = [__('Sent this month'), (string) ($u['sent_month'] ?? '--')];
            $rows[] = [__('Failed this month'), (string) ($u['failed_month'] ?? '--')];
            $rows[] = [__('Last sent'), $u['last_at'] ? \Carbon\Carbon::parse($u['last_at'])->format('d M Y H:i') : '--'];
        }
        $c[] = self::part('sms', '💬', __('SMS credit and sending'), $cloud['sections']['sms'] ?? ['rating' => 'unknown', 'score' => null, 'actions' => []], $rows);

        // ---- cloud drive
        $dr = $cloud['drive'] ?? null;
        $rows = [];
        if ($ok($dr) && ! empty($dr['connected'])) {
            $rows = [[__('Provider'), $dr['provider']], [__('Used'), $dr['used_gb'] . ' GB' . ($dr['limit_gb'] ? ' of ' . $dr['limit_gb'] . ' GB' : '')], [__('Still free'), $dr['left_gb'] === null ? __('no limit') : $dr['left_gb'] . ' GB'], [__('In the bin'), $dr['trash_gb'] . ' GB']];
        } else {
            $rows = [[__('Google Drive'), __('not connected')]];
        }
        $rows[] = ['OneDrive', __('not connected to WIMS')];
        $c[] = self::part('drive', '☁️', __('Cloud drive space'), $cloud['sections']['drive'] ?? ['rating' => 'unknown', 'score' => null, 'actions' => []], $rows);

        // ---- backups and scheduled work (with the failed jobs)
        if (isset($sec['backups'])) {
            $b = $core['backups'] ?? [];
            $j = $core['jobs'] ?? [];
            $rows = [
                [__('Last backup'), ! empty($b['last_at']) ? \Carbon\Carbon::parse($b['last_at'])->format('d M Y H:i') . ' (' . $b['hours_ago'] . ' h ' . __('ago') . ')' : __('none recorded')],
                [__('Automatic backups'), ! empty($b['auto_enabled']) ? __('on') : __('off')], [__('Next backup due'), ! empty($b['next_due_at']) ? \Carbon\Carbon::parse($b['next_due_at'])->format('d M Y H:i') : '--'],
                [__('Scheduler last beat'), isset($j['scheduler_minutes_ago']) && $j['scheduler_minutes_ago'] !== null ? $j['scheduler_minutes_ago'] . ' ' . __('minute(s) ago') : __('never seen')],
                [__('Jobs waiting / failed'), ($j['pending'] ?? '--') . ' / ' . ($j['failed'] ?? '--')],
            ];
            $c[] = self::part('backups', '🛡️', __('Backups and scheduled work'), $sec['backups'], $rows, self::failedJobs());
        }

        // ---- people, errors and safety
        if (isset($sec['safety'])) {
            $p = $core['people'] ?? [];
            $l = $core['logs'] ?? [];
            $s = $core['slow'] ?? [];
            $rows = [
                [__('People using WIMS now'), (string) ($p['online_now'] ?? '--') . ' (' . ($p['active_users'] ?? '--') . ' ' . __('active accounts') . ')'],
                [__('Sign-ins today / failed'), ($p['logins_today'] ?? '--') . ' / ' . ($p['failed_logins_today'] ?? '--')],
                [__('Errors written to the log today'), (string) ($l['errors_today'] ?? '--') . (isset($l['log_mb']) ? ' (' . __('log file') . ' ' . $l['log_mb'] . ' MB)' : '')],
                [__('Slow pages / slow queries, 24 h'), ($s['pages_24h'] ?? '--') . ' / ' . ($s['queries_24h'] ?? '--')],
            ];
            foreach ((array) ($s['worst'] ?? []) as $w) {
                $rows[] = [__('Slow page') . ': ' . self::scrub($w['label']), $w['ms'] . ' ms'];
            }
            $c[] = self::part('safety', '📡', __('People and safety'), $sec['safety'], $rows, array_merge(self::errorGroups(), self::slowQueries()));
        }

        // ---- licence
        if (isset($sec['licence']) && $ok($core['licence'] ?? null)) {
            $c[] = self::part('licence', '🔑', __('Licence'), $sec['licence'], [[__('Status'), (string) ($core['licence']['label'] ?? '--')], [__('Days left'), (string) ($core['licence']['days_left'] ?? '--')]]);
        }

        // ---- the whole
        $rated = array_values(array_filter($c, fn ($p) => $p['score'] !== null));
        $score = $rated ? (int) round(array_sum(array_column($rated, 'score')) / count($rated) * 0.6 + min(array_column($rated, 'score')) * 0.4) : 0;
        $counts = array_fill_keys(['excellent', 'very_good', 'good', 'fair', 'bad'], 0);
        foreach ($c as $p) {
            if (isset($counts[$p['rating']])) {
                $counts[$p['rating']]++;
            }
        }
        $top = [];
        $sorted = $c;
        usort($sorted, fn ($a, $b) => ($a['score'] ?? 100) <=> ($b['score'] ?? 100));
        foreach ($sorted as $p) {
            foreach ($p['actions'] as $a) {
                if (count($top) < 6 && ($p['score'] ?? 100) < 80) {
                    $top[] = ['part' => $p['title'], 'text' => $a, 'rating' => $p['rating']];
                }
            }
        }

        return [
            'generated_at' => now()->format('d M Y, H:i'), 'host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'WIMS', 'by' => $user->name,
            'score' => $score, 'rating' => Collector::rate($score), 'counts' => $counts, 'top' => $top, 'parts' => $c,
        ];
    }

    private static function part(string $key, string $icon, string $title, array $section, array $figures, array $errors = []): array
    {
        return [
            'key' => $key, 'icon' => $icon, 'title' => $title, 'score' => $section['score'] ?? null, 'rating' => $section['rating'] ?? 'unknown', 'note' => $section['note'] ?? '',
            'actions' => array_values(array_filter(array_map(fn ($a) => $a['text'] ?? null, $section['actions'] ?? []))), 'figures' => $figures, 'errors' => $errors,
        ];
    }

    private static function mb($v): string
    {
        return $v >= 1024 ? round($v / 1024, 1) . ' GB' : round($v) . ' MB';
    }

    /** Today's error messages, grouped: the first line of each, scrubbed, how many times, when last. */
    public static function errorGroups(): array
    {
        $file = storage_path('logs/laravel-' . now()->format('Y-m-d') . '.log');
        if (! is_file($file)) {
            $file = storage_path('logs/laravel.log');
        }
        if (! is_file($file)) {
            return [];
        }
        $fh = @fopen($file, 'r');
        if (! $fh) {
            return [];
        }
        $len = filesize($file);
        fseek($fh, max(0, $len - 2097152));
        $chunk = (string) fread($fh, 2097152);
        fclose($fh);
        $day = preg_quote(now()->format('Y-m-d'), '/');
        preg_match_all('/^\[(' . $day . ' [0-9:]{8})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/m', $chunk, $m, PREG_SET_ORDER);
        $groups = [];
        foreach ($m as $row) {
            $text = self::scrub($row[3]);
            $key = md5(preg_replace('/\d+/', '#', $text));
            $groups[$key] ??= ['text' => $text, 'count' => 0, 'last' => $row[1], 'level' => $row[2]];
            $groups[$key]['count']++;
            $groups[$key]['last'] = $row[1];
        }
        usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_map(fn ($g) => ['label' => $g['level'], 'text' => $g['text'], 'count' => $g['count'], 'at' => substr($g['last'], 11, 5)], array_slice($groups, 0, 8));
    }

    private static function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get(['queue', 'failed_at', 'exception'])->map(fn ($r) => [
            'label' => __('Failed job') . ' (' . $r->queue . ')', 'text' => self::scrub(strtok((string) $r->exception, "\n") ?: ''), 'count' => null, 'at' => substr((string) $r->failed_at, 5, 11),
        ])->all();
    }

    private static function slowQueries(): array
    {
        if (! Schema::hasTable('slow_logs')) {
            return [];
        }

        return DB::table('slow_logs')->where('kind', 'query')->where('created_at', '>=', now()->subDay())->orderByDesc('duration_ms')->limit(3)->get(['label', 'duration_ms', 'created_at'])->map(fn ($r) => [
            'label' => __('Slow query') . ' ' . (int) $r->duration_ms . ' ms', 'text' => self::scrub(mb_substr((string) $r->label, 0, 180)), 'count' => null, 'at' => substr((string) $r->created_at, 11, 5),
        ])->all();
    }

    /** Takes out of a log line everything that could be personal or secret before it goes into an e-mail. When in doubt it removes more, never less. */
    public static function scrub(string $s): string
    {
        // a logged context ({"userId":1,...}) is dropped, and so is everything after SQL: (a database message repeats the query with its values)
        $s = preg_replace('/\s\{".*$/s', '', $s);
        $s = preg_replace('/\(?(?:Connection: \w+, )?SQL:.*$/s', '(query hidden)', $s);
        // the value of a unique-key clash is a person's data
        $s = preg_replace("/(Duplicate entry )'[^']*'/i", "$1'[value]'", $s);
        // web addresses with a name and password in them
        $s = preg_replace('~(\w+://)[^/\s@]+@~', '$1[hidden]@', $s);
        // names of server folders: only the file name stays
        try {
            $base = base_path();
        } catch (\Throwable $e) {
            $base = ''; // used outside the application (a plain test): the general folder rule below still applies
        }
        if ($base !== '') {
            $s = str_replace([$base . '/', $base], '', $s);
        }
        $s = preg_replace('~(?:/[\w.\-]+){3,}/([\w.\-]+)~', '.../$1', $s);
        // tokens: Bearer ..., JSON web tokens, long ids
        $s = preg_replace('/\bBearer\s+\S+/i', 'Bearer [hidden]', $s);
        $s = preg_replace('/\beyJ[\w-]+\.[\w-]+\.[\w-]+/', '[token]', $s);
        // anything NAMED like a secret (access_token, DB_PASSWORD, client-secret, api key, private key ...), with its quoted or bare value, or the words "is"/":"/"=" before it
        $s = preg_replace('/[\w-]*(?:password|passwd|pwd|secret|token|api[_\s-]?key|apikey|authorization|credential|private[_\s-]?key|access[_\s-]?key|passphrase|otp|pin)[\w-]*\s*(?:[:=]|\bis\b|\bwas\b)?\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i', '[secret hidden]', $s);
        $s = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $s);
        $s = preg_replace('/\b[A-Fa-f0-9]{24,}\b/', '[id]', $s);
        // telephone numbers, written with spaces, dashes or brackets
        $s = preg_replace('/\+?\d[\d\s().-]{7,}\d/', '[number]', $s);
        $s = preg_replace('/\b\d{7,}\b/', '#', $s);
        $s = preg_replace('/\s+/', ' ', trim($s));

        return mb_substr($s, 0, 240);
    }
}
