<?php

namespace App\Support\Optimizer;

use App\Models\OptimizerRun;
use App\Models\User;

/**
 * Looks (scan) or does (run) an optimisation and writes the audit record: who ran it, which tasks, what was measured before and after (space and database speed),
 * what each task did. Everything is measured by the same code before and after, so the gain in the history is a measurement.
 */
class Runner
{
    /** @return array{tasks: array, snapshot: array, tables: array, options: array} */
    public static function scan(): array
    {
        $catalog = Tasks::catalog();
        $tasks = [];
        foreach (Tasks::ALL as $key) {
            $tasks[] = ['key' => $key, 'default' => in_array($key, Tasks::DEFAULT, true)] + $catalog[$key] + Tasks::scan($key);
        }

        return ['tasks' => $tasks, 'snapshot' => Metrics::snapshot(), 'tables' => Metrics::tables(8), 'options' => OptimizerSettings::all()];
    }

    /** @param  array<int, string>  $tasks */
    public static function run(array $tasks, ?User $by): OptimizerRun
    {
        $tasks = array_values(array_intersect(Tasks::ALL, $tasks));
        $options = OptimizerSettings::all();
        @set_time_limit($options['opt_seconds'] + 120);
        $started = time();
        $run = OptimizerRun::create([
            'account_id' => optional($by)->account_id, 'user_id' => optional($by)->id, 'user_name' => optional($by)->name ?: __('System'),
            'status' => 'running', 'tasks' => $tasks, 'options' => $options, 'started_at' => now(),
        ]);

        try {
            $before = Metrics::snapshot();
            $speedBefore = SpeedProbe::run();
            $ctx = ['deadline' => $started + $options['opt_seconds'], 'run' => $run->id, 'options' => $options];
            $results = [];
            $failed = 0;
            foreach ($tasks as $key) {
                $t = microtime(true);
                try {
                    $r = Tasks::run($key, $ctx);
                } catch (\Throwable $e) {
                    $failed++;
                    $r = ['ok' => false, 'summary' => __('This step stopped with an error.'), 'changed' => 0, 'bytes' => 0, 'notes' => [substr($e->getMessage(), 0, 240)], 'items' => []];
                }
                $r['title'] = Tasks::catalog()[$key]['title'];
                $r['seconds'] = round(microtime(true) - $t, 1);
                $results[$key] = $r;
            }
            $after = Metrics::snapshot();
            $speedAfter = SpeedProbe::run();
            $saved = Metrics::footprint($before) - Metrics::footprint($after);

            $run->update([
                'status' => $failed ? ($failed === count($tasks) ? 'failed' : 'partial') : 'done',
                'before' => $before, 'after' => $after, 'results' => $results, 'speed_before' => $speedBefore, 'speed_after' => $speedAfter,
                'saved_bytes' => $saved, 'files_changed' => (int) (($results['images']['changed'] ?? 0) + ($results['logs']['changed'] ?? 0) + ($results['technical']['changed'] ?? 0)),
                'tables_changed' => (int) ($results['database']['changed'] ?? 0),
                'speed_before_ms' => $speedBefore['total_ms'], 'speed_after_ms' => $speedAfter['total_ms'],
                'seconds' => time() - $started, 'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 500), 'seconds' => time() - $started, 'finished_at' => now()]);
        }

        // the audit trail of the whole application records it too (who, when, what, how much)
        try {
            log_activity(__('Data optimised'), ['run' => $run->id, 'tasks' => $tasks, 'status' => $run->status, 'saved_bytes' => $run->saved_bytes, 'speed_before_ms' => $run->speed_before_ms, 'speed_after_ms' => $run->speed_after_ms], $by, 'Setting');
        } catch (\Throwable $e) {
            // the optimiser's own history above is the record
        }

        return $run->fresh();
    }

    /** "12.3 MB" */
    public static function bytes(int|float $b): string
    {
        $b = (float) $b;
        $neg = $b < 0;
        $b = abs($b);
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $u) {
            if ($b < 1024 || $u === 'TB') {
                return ($neg ? '-' : '') . ($i === 0 ? (string) (int) $b : number_format($b, $b < 10 ? 2 : 1)) . ' ' . $u;
            }
            $b /= 1024;
        }

        return '0 B';
    }
}
