<?php

namespace App\Support\Optimizer;

use App\Models\OptimizerRun;
use App\Models\User;

/**
 * Looks (scan) or does (run) an optimisation and writes the audit record: who ran it, which tasks, what was measured before and after (space and database speed),
 * what each task did, and the general status with its reasons. The run is done in steps (begin, one step per task, finish) so the screen can show what is
 * happening; run() does all the steps in a row (command line, tests).
 */
class Runner
{
    public const RATINGS = ['excellent' => 'Excellent', 'very_good' => 'Very Good', 'good' => 'Good', 'attention' => 'Needs attention'];

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
    public static function begin(array $tasks, ?User $by): OptimizerRun
    {
        $tasks = array_values(array_intersect(Tasks::ALL, $tasks));
        $options = OptimizerSettings::all();
        @set_time_limit($options['opt_seconds'] + 120);

        return OptimizerRun::create([
            'account_id' => optional($by)->account_id, 'user_id' => optional($by)->id, 'user_name' => optional($by)->name ?: __('System'),
            'status' => 'running', 'tasks' => $tasks, 'options' => $options, 'started_at' => now(),
            'before' => Metrics::snapshot(), 'speed_before' => SpeedProbe::run(), 'results' => [],
        ]);
    }

    /** One task. A task that stopped at its time limit says `more`: the caller calls again until it does not. The answers add up. */
    public static function step(OptimizerRun $run, string $key): array
    {
        abort_unless(in_array($key, (array) $run->tasks, true) && $run->status === 'running', 422);
        $options = (array) $run->options;
        @set_time_limit($options['opt_seconds'] + 120);
        $t = microtime(true);
        try {
            $r = Tasks::run($key, ['deadline' => time() + $options['opt_seconds'], 'run' => $run->id, 'options' => $options]);
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'more' => false, 'summary' => __('This step stopped with an error.'), 'changed' => 0, 'bytes' => 0, 'notes' => [substr($e->getMessage(), 0, 240)], 'items' => []];
        }
        $r['title'] = Tasks::catalog()[$key]['title'];
        $r['seconds'] = round(microtime(true) - $t, 1);

        $all = (array) $run->results;
        if (isset($all[$key])) { // a second or third call of the same task: add to what it has done so far
            $p = $all[$key];
            $r['changed'] += (int) ($p['changed'] ?? 0);
            $r['bytes'] += (int) ($p['bytes'] ?? 0);
            $r['seconds'] = round($r['seconds'] + (float) ($p['seconds'] ?? 0), 1);
            $r['items'] = array_slice(array_merge((array) ($p['items'] ?? []), (array) $r['items']), 0, 400);
            $r['notes'] = array_values(array_unique(array_merge((array) ($p['notes'] ?? []), (array) $r['notes'])));
            $r['ok'] = $r['ok'] && ($p['ok'] ?? true);
        }
        $all[$key] = $r;
        $run->update(['results' => $all]);

        return $r;
    }

    public static function finish(OptimizerRun $run, ?User $by): OptimizerRun
    {
        $results = (array) $run->results;
        $tasks = (array) $run->tasks;
        $failed = count(array_filter($tasks, fn ($k) => isset($results[$k]) && ! ($results[$k]['ok'] ?? true)));
        $notRun = count(array_filter($tasks, fn ($k) => ! isset($results[$k])));
        $before = (array) $run->before;
        $after = Metrics::snapshot();
        $speedAfter = SpeedProbe::run();
        $remaining = [];
        foreach (array_intersect($tasks, ['database', 'images', 'logs']) as $k) {
            $s = Tasks::scan($k);
            $remaining[$k] = ['bytes' => (int) $s['bytes'], 'items' => (int) $s['items']];
        }
        $gross = (int) array_sum(array_map(fn ($r) => (int) ($r['bytes'] ?? 0), $results));
        $status = ($failed || $notRun) ? (($failed + $notRun) >= count($tasks) ? 'failed' : 'partial') : 'done';

        $data = [
            'status' => $status, 'after' => $after, 'speed_after' => $speedAfter, 'remaining' => $remaining,
            'saved_bytes' => $gross, 'speed_before_ms' => ((array) $run->speed_before)['total_ms'] ?? null, 'speed_after_ms' => $speedAfter['total_ms'],
            'files_changed' => (int) (($results['images']['changed'] ?? 0) + ($results['logs']['changed'] ?? 0) + ($results['technical']['changed'] ?? 0)),
            'tables_changed' => (int) ($results['database']['changed'] ?? 0),
            'seconds' => (int) abs(now()->diffInSeconds($run->started_at)), 'finished_at' => now(),
        ];
        $run->fill($data);
        [$rating, $why] = self::rate($run, $before, $after);
        $run->update($data + ['rating' => $rating, 'rating_why' => $why]);

        // the audit trail of the whole application records it too (who, when, what, how much)
        try {
            log_activity(__('Data optimised'), ['run' => $run->id, 'tasks' => $tasks, 'status' => $status, 'rating' => $rating, 'saved_bytes' => $gross, 'speed_before_ms' => $run->speed_before_ms, 'speed_after_ms' => $run->speed_after_ms], $by, 'Setting');
        } catch (\Throwable $e) {
            // the optimiser's own history is the record
        }

        return $run->fresh();
    }

    /** All the steps in a row. */
    public static function run(array $tasks, ?User $by): OptimizerRun
    {
        $run = self::begin($tasks, $by);
        try {
            foreach ((array) $run->tasks as $key) {
                for ($i = 0; $i < 8; $i++) {
                    if (! (self::step($run->fresh(), $key)['more'] ?? false)) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $run->update(['error' => substr($e->getMessage(), 0, 500)]);
        }

        return self::finish($run->fresh(), $by);
    }

    /**
     * The general status and the reasons for it.
     *   Excellent       every step finished, nothing worth gaining is left, and nothing got worse
     *   Very Good       every step finished and what is left is small
     *   Good            every step finished, but a fair amount is still left to gain (run it again, or change the numbers)
     *   Needs attention a step failed or did not run, or most of the gain is still waiting
     *
     * @return array{0:string,1:array<int,string>}
     */
    public static function rate(OptimizerRun $run, array $before, array $after): array
    {
        $results = (array) $run->results;
        $tasks = (array) $run->tasks;
        $why = [];
        $failed = array_filter($tasks, fn ($k) => isset($results[$k]) && ! ($results[$k]['ok'] ?? true));
        $notRun = array_filter($tasks, fn ($k) => ! isset($results[$k]));
        $left = (int) array_sum(array_map(fn ($r) => (int) ($r['bytes'] ?? 0), (array) $run->remaining));
        $leftItems = (int) array_sum(array_map(fn ($r) => (int) ($r['items'] ?? 0), (array) $run->remaining));
        $base = max(1, Metrics::footprint($before));
        $gain = (int) $run->saved_bytes;
        $sb = (float) $run->speed_before_ms;
        $sa = (float) $run->speed_after_ms;
        $speed = $sb > 0 ? round((($sb - $sa) / $sb) * 100, 1) : 0.0;

        if ($failed) {
            $why[] = __(':n step(s) stopped with an error: :t.', ['n' => count($failed), 't' => implode(', ', array_map(fn ($k) => Tasks::catalog()[$k]['title'], $failed))]);
        }
        if ($notRun) {
            $why[] = __(':n step(s) did not run.', ['n' => count($notRun)]);
        }
        if (! $failed && ! $notRun) {
            $why[] = __('All :n step(s) finished without an error.', ['n' => count($tasks)]);
        }
        $why[] = $gain > 0
            ? __(':s was gained (:p% of what the program occupies).', ['s' => self::bytes($gain), 'p' => number_format($gain / $base * 100, 1)])
            : __('Nothing needed to be made smaller: the data was already compact.');
        if ($leftItems === 0 || $left < 1024 * 1024) {
            $why[] = __('Nothing worth gaining is left: pictures are within the size limit, old logs are zipped and no table holds wasted space.');
        } else {
            $why[] = __(':s could still be gained from :n item(s): run it again or change the numbers.', ['s' => self::bytes($left), 'n' => $leftItems]);
        }
        $noise = abs($speed) < 5 || abs($sa - $sb) < 5; // a few percent, or a few milliseconds, is the server's own movement
        $why[] = $noise
            ? __('Database reads measured about the same before and after (:a ms and :b ms): the difference is within the normal movement of the server.', ['a' => number_format($sb, 1), 'b' => number_format($sa, 1)])
            : ($speed > 0 ? __('Database reads were :p% faster (:a ms down to :b ms).', ['p' => $speed, 'a' => number_format($sb, 1), 'b' => number_format($sa, 1)])
                : __('Database reads were :p% slower (:a ms up to :b ms): the figure moves with what else the server was doing; measure again later.', ['p' => abs($speed), 'a' => number_format($sb, 1), 'b' => number_format($sa, 1)]));

        $rating = 'good';
        if ($failed || $notRun) {
            $rating = 'attention';
        } elseif ($left > 0.2 * $base && $left > 50 * 1024 * 1024) {
            $rating = 'attention';
        } elseif ($leftItems === 0 || $left < 1024 * 1024) {
            $rating = ($noise || $speed > -10) ? 'excellent' : 'very_good';
        } elseif ($left < 0.03 * $base || $left < 10 * 1024 * 1024) {
            $rating = 'very_good';
        }

        return [$rating, $why];
    }

    public static function ratingLabel(?string $rating): string
    {
        return __(self::RATINGS[$rating ?? ''] ?? 'Not rated');
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
