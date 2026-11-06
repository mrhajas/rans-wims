<?php

namespace App\Console\Commands;

use App\Models\WimsLogRun;
use App\Support\Optimizer\Runner;
use App\Support\WimsLogs\Archiver;
use App\Support\WimsLogs\Sources;
use App\Support\WimsLogs\WimsLogSettings;
use Illuminate\Console\Command;

/**
 * Archives old log records (see App\Support\WimsLogs). With --scheduled (what the nightly scheduler uses) it does nothing unless WIMS Logs is switched on and the number
 * of days between runs has passed since the last one. Without it, it runs now. --preview only shows what would be moved.
 */
class WimsLogsRun extends Command
{
    protected $signature = 'wims-logs:run {--scheduled : only when it is due (the number of days set in Settings > WIMS Logs)} {--source=* : only these logs} {--preview : show what would be moved, change nothing}';

    protected $description = 'Move old log records into compressed archives that a Super Admin can read (Settings > WIMS Logs)';

    public function handle(): int
    {
        if ($this->option('preview')) {
            foreach (Archiver::preview() as $p) {
                $this->line(sprintf('%-40s live %7d rows %9s | due %7d rows (~%s) older than %d days', $p['label'], $p['live_rows'], Runner::bytes($p['live_bytes']), $p['due_rows'], Runner::bytes($p['due_bytes']), $p['keep_days']));
            }

            return self::SUCCESS;
        }

        if ($this->option('scheduled')) {
            if (! WimsLogSettings::get('wimslogs_enabled')) {
                return self::SUCCESS;
            }
            $last = WimsLogRun::query()->where('trigger', 'schedule')->whereIn('status', ['done', 'partial'])->latest('started_at')->value('started_at');
            if ($last && now()->diffInDays($last, true) < WimsLogSettings::get('wimslogs_every_days') - 0.5) {
                return self::SUCCESS;
            }
        }

        $only = (array) $this->option('source');
        foreach ($only as $k) {
            if (! Sources::get($k)) {
                $this->error("Unknown log \"$k\". Logs: " . implode(', ', Sources::keys()));

                return self::FAILURE;
            }
        }

        try {
            $run = Archiver::run($this->option('scheduled') ? 'schedule' : 'manual', null, $only ?: null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s %s: %d records archived, %s in the database -> %s in the archive, database files %s smaller, %d s.', $run->ref(), $run->status, $run->rows_archived,
            Runner::bytes($run->original_bytes), Runner::bytes($run->stored_bytes), Runner::bytes(max(0, $run->db_freed_bytes)), $run->seconds));
        foreach ($run->archives as $a) {
            $this->line(sprintf('  %-12s %7d records  %9s -> %9s  %s%s', $a->source, $a->rows_n, Runner::bytes($a->original_bytes), Runner::bytes($a->stored_bytes), $a->verified ? 'verified' : 'NOT VERIFIED', $a->error ? ' | ' . $a->error : ''));
        }

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
