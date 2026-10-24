<?php

namespace App\Support\Performance;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * The daily clean-up. Everything it removes has no long-term value (old weather readings, read bell alerts, server log files, slow-query records, expired sign-in
 * tokens) and every number is a setting. Activity history is NOT deleted: it is moved to the archive (ActivityArchiver). Audit data such as login logs,
 * approvals and adjustment history is never touched.
 */
class Housekeeping
{
    public const LAST_KEY = 'perf_last_housekeeping';

    /** @return array<string, mixed> what was done, for the log and for the Settings page */
    public static function run(): array
    {
        $r = ['at' => now()->toDateTimeString()];

        $a = ActivityArchiver::run();
        $r['activity_archived'] = $a['moved'];

        $r['notifications_removed'] = self::removeOlder('notifications', 'created_at', PerfSettings::get('perf_notification_keep_days'), fn ($q) => $q->whereNotNull('read_at'));
        $r['weather_removed'] = self::removeOlder('weather_snapshots', 'created_at', PerfSettings::get('perf_weather_keep_days'));
        $r['slow_removed'] = self::removeOlder('slow_logs', 'created_at', PerfSettings::get('perf_slow_keep_days'));
        $r['failed_jobs_removed'] = self::removeOlder('failed_jobs', 'failed_at', 60);
        $r['reset_tokens_removed'] = self::removeOlder('password_reset_tokens', 'created_at', 2);
        $r['sessions_removed'] = self::expiredSessions();
        $r['exports_removed'] = Tables::has('activity_archive_exports') ? ArchiveExport::purge(7) : 0;
        $r['log_files_removed'] = self::logFiles(PerfSettings::get('perf_log_keep_days'));
        $r['schedule_log_trimmed'] = self::trimScheduleLog();

        try {
            Setting::updateOrCreate(['tec_key' => self::LAST_KEY, 'account_id' => 1], ['tec_value' => json_encode($r)]);
        } catch (\Throwable $e) {
            // the summary is only for the Settings page
        }

        return $r;
    }

    public static function last(): ?array
    {
        $v = get_settings(self::LAST_KEY);

        return $v ? json_decode((string) $v, true) : null;
    }

    private static function removeOlder(string $table, string $column, int $days, ?callable $extra = null): int
    {
        if ($days <= 0 || ! \App\Support\Performance\Tables::has($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }
        $total = 0;
        // in slices, so a big table never holds the database for long
        do {
            $q = DB::table($table)->where($column, '<', now()->subDays($days));
            if ($extra) {
                $extra($q);
            }
            $n = $q->limit(5000)->delete();
            $total += $n;
        } while ($n === 5000);

        return $total;
    }

    private static function expiredSessions(): int
    {
        if (config('session.driver') !== 'database' || ! \App\Support\Performance\Tables::has(config('session.table', 'sessions'))) {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))->where('last_activity', '<', time() - ((int) config('session.lifetime', 120) * 60 * 2))->delete();
    }

    /** Daily server log files older than the setting. */
    private static function logFiles(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $n = 0;
        foreach (File::glob(storage_path('logs/laravel-*.log')) as $file) {
            if (filemtime($file) < now()->subDays($days)->getTimestamp()) {
                @unlink($file) && $n++;
            }
        }

        return $n;
    }

    /** The scheduler's own output file grows for ever (cron appends to it): over 5 MB it keeps only the last 1 MB. */
    private static function trimScheduleLog(): bool
    {
        $file = storage_path('logs/schedule.log');
        if (! is_file($file) || filesize($file) <= 5 * 1048576) {
            return false;
        }
        $h = fopen($file, 'r+');
        if (! $h) {
            return false;
        }
        fseek($h, -1048576, SEEK_END);
        fgets($h); // start on a whole line
        $tail = stream_get_contents($h);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, "-- older lines removed by the daily clean-up --\n" . $tail);
        fclose($h);

        return true;
    }
}
