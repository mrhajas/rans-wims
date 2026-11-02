<?php

namespace App\Services\BackgroundTasks;

use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\FeatureManager;
use App\Support\BackgroundTasks\Registry;
use App\Support\BackgroundTasks\TaskException;
use App\Support\BackgroundTasks\TaskKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Background Tasks. A task is a long READ-ONLY report prepared by a separate process while the person keeps working.
 *
 * Safety rules kept in this one place:
 *  - Only kinds listed in Registry can run; each wraps an existing report action and runs it AS the person who started it, after asking its ability again.
 *  - The same person cannot have the same job twice at once: `active_lock` is a UNIQUE column, set while queued/running and emptied the moment the task ends
 *    (done, failed or swept), so the database itself refuses the second one (the first one is returned instead).
 *  - A task is claimed with a single conditional UPDATE (queued -> running), so two processes can never run the same task.
 *  - The finished file is on the PRIVATE disk (storage/app), under a random name, and is handed over only by an authorised route.
 *  - No retries: a failed task says so honestly and the person starts it again if they want.
 *  - Switched off in Global Feature Control (or the setting `background_tasks_enabled` = 0), nothing is started and every report is made on the page as before.
 */
class TaskService
{
    public const RUNNING_STUCK_MINUTES = 25;   // a running task whose process is gone, or silent this long, is failed
    public const QUEUED_GIVE_UP_MINUTES = 10;  // a task nobody picked up (no process could be started and the scheduler is not running)
    public const KEEP_FILE_DAYS = 7;
    public const KEEP_ROW_DAYS = 60;

    public static function enabled(): bool
    {
        try {
            $setting = get_settings('background_tasks_enabled');
            if ($setting !== null && in_array(strtolower(trim((string) $setting)), ['0', 'false', 'off', 'no'], true)) {
                return false;
            }

            return FeatureManager::isEnabled('background_tasks');
        } catch (\Throwable $e) {
            return false; // if the switches cannot be read, the old way (the report on its page) is the safe one
        }
    }

    /** A line in the activity log. The log is a convenience: a failure to write it must never fail, or hide, the task itself. */
    public static function note(string $text, array $properties, ?BackgroundTask $task): void
    {
        try {
            log_activity($text, $properties, $task, 'Background Task');
        } catch (\Throwable $e) {
            // nothing: the task row itself is the record
        }
    }

    /**
     * Queues the job, or returns the one the person already has (same job, still running).
     *
     * @return array{0: BackgroundTask, 1: bool} the task and whether it is new
     */
    public static function start(User $user, TaskKind $kind, array $params): array
    {
        $lock = mb_substr($user->account_id . '|' . $user->id . '|' . $kind->subject($params), 0, 190);
        try {
            $task = BackgroundTask::query()->create([
                'account_id' => $user->account_id, 'user_id' => $user->id, 'kind' => $kind->key(), 'label' => $kind->label($params),
                'subject_key' => mb_substr($kind->subject($params), 0, 120), 'params' => $params ?: null, 'status' => BackgroundTask::QUEUED,
                'message' => __('Waiting to start'), 'active_lock' => $lock, 'attempts' => 0,
            ]);
        } catch (QueryException $e) {
            $existing = self::isDuplicate($e) ? BackgroundTask::query()->where('active_lock', $lock)->first() : null;
            if ($existing) {
                return [$existing, false];
            }
            if (self::isDuplicate($e)) {
                // the other one finished between the refusal and the look: its lock is free now, so this one is simply started
                usleep(100000);

                return self::start($user, $kind, $params);
            }
            throw $e;
        }
        self::note('Background task queued', ['kind' => $task->kind, 'task' => $task->id, 'by' => $user->id], $task);
        self::spawn($task->id);

        return [$task, true];
    }

    private static function isDuplicate(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) == 1062 || str_contains((string) $e->getMessage(), 'Duplicate entry') || str_contains((string) $e->getMessage(), 'UNIQUE constraint');
    }

    /** Starts `background:run {id}` as a separate process. False when this server does not allow it (the scheduler's sweep will then run the task, within a minute). */
    public static function spawn(int $id): bool
    {
        if (app()->runningUnitTests()) {
            return false; // a test must never start a real process against the real database
        }
        try {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            if (! function_exists('proc_open') || in_array('proc_open', $disabled, true) || DIRECTORY_SEPARATOR === '\\') {
                return false;
            }
            $php = self::phpBinary();
            if (! $php) {
                return false;
            }
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg(base_path('artisan')) . ' background:run ' . (int) $id . ' > /dev/null 2>&1 &';
            $process = @proc_open(['/bin/sh', '-c', $cmd], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                return false;
            }
            @proc_close($process); // the shell returns at once; the task keeps running on its own

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The command-line PHP (a web server's own binary is not it). */
    public static function phpBinary(): ?string
    {
        $candidates = array_filter([
            config('app.background_php_binary'),
            PHP_SAPI === 'cli' ? PHP_BINARY : null,
            PHP_BINDIR . '/php',
            '/opt/cpanel/ea-php83/root/usr/bin/php', '/opt/cpanel/ea-php82/root/usr/bin/php', '/opt/cpanel/ea-php81/root/usr/bin/php',
            '/usr/local/bin/php', '/usr/bin/php',
        ]);
        foreach ($candidates as $path) {
            if (is_string($path) && @is_file($path) && @is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** One conditional UPDATE: only one process can move a task from queued to running. */
    public static function claim(int $id): bool
    {
        return DB::table('background_tasks')->where('id', $id)->where('status', BackgroundTask::QUEUED)->update([
            'status' => BackgroundTask::RUNNING, 'started_at' => now(), 'last_heartbeat_at' => now(), 'attempts' => DB::raw('attempts + 1'),
            'pid' => function_exists('getmypid') ? getmypid() : null, 'message' => __('Preparing the report'), 'progress' => 5, 'updated_at' => now(),
        ]) === 1;
    }

    /** Runs a queued task now, in this process. Returns the final status, or null when it was not this process's to run. */
    public static function run(int $id): ?string
    {
        if (! self::claim($id)) {
            return null;
        }
        $task = BackgroundTask::query()->find($id);
        $finished = false;
        register_shutdown_function(function () use ($id, &$finished) {
            // a fatal error (memory, time) skips every catch: the task must not stay "running" with its lock held (a task that ended normally needs nothing)
            if ($finished) {
                return;
            }
            self::fail($id, __('The report stopped before it was finished (the server ran out of time or memory). Try again with a narrower selection.'), 'process ended');
        });

        try {
            $kind = Registry::get($task->kind);
            if (! $kind) {
                throw new TaskException(__('This kind of report is no longer available.'));
            }
            if (! self::enabled()) {
                throw new TaskException(__('Background tasks were switched off before this one could start. Make the report from its page.'));
            }
            $user = User::withoutGlobalScopes()->find($task->user_id);
            if (! $user || ! $user->is_active || (int) $user->account_id !== (int) $task->account_id) {
                throw new TaskException(__('The person who asked for this report can no longer sign in, so it was not made.'));
            }
            // from here on everything runs as that person, inside their own account, exactly as on the page
            Auth::guard('web')->setUser($user);
            Auth::shouldUse('web');
            session(['account_id' => $user->account_id]);
            if (! $kind->allowed($user)) {
                throw new AuthorizationException(__('You no longer have permission to make this report.'));
            }
            self::beat($id, 15, __('Collecting the records'));
            $file = $kind->build((array) ($task->params ?? []), $user);

            self::beat($id, 90, __('Saving the file'));
            $path = 'background-tasks/' . $task->account_id . '/' . Str::random(40) . '.' . (pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'pdf');
            if (! Storage::disk('local')->put($path, $file['content'])) {
                throw new TaskException(__('The file could not be saved on the server.'));
            }
            $saved = DB::table('background_tasks')->where('id', $id)->where('status', BackgroundTask::RUNNING)->update([
                'status' => BackgroundTask::DONE, 'progress' => 100, 'message' => __('Ready'), 'result_path' => $path, 'result_name' => mb_substr($file['name'], 0, 160),
                'result_mime' => mb_substr($file['mime'], 0, 80), 'result_size' => strlen($file['content']), 'error' => null, 'active_lock' => null, 'pid' => null,
                'finished_at' => now(), 'last_heartbeat_at' => now(), 'updated_at' => now(),
            ]);
            if ($saved !== 1) {
                // the task was ended by someone else while it ran (swept as stuck, or cancelled): the file is not wanted
                Storage::disk('local')->delete($path);

                return null;
            }
            self::note('Background task finished', ['kind' => $task->kind, 'task' => $id, 'size' => strlen($file['content'])], $task);

            return BackgroundTask::DONE;
        } catch (AuthorizationException $e) {
            return self::fail($id, $e->getMessage() ?: __('You no longer have permission to make this report.'), 'not allowed');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return self::fail($id, __('One of the choices for this report is not valid any more. Start it again.'), 'invalid choices');
        } catch (\Throwable $e) {
            report($e);
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && in_array($e->getStatusCode(), [401, 403], true)) {
                return self::fail($id, __('You no longer have permission to make this report.'), 'forbidden');
            }
            // only our own exceptions carry words meant for the person; a database or library message is never shown
            $shown = $e instanceof TaskException ? $e->getMessage() : __('The report could not be made. Try again, or make it from its page. The reason is written in the system log.');

            return self::fail($id, $shown, mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 250));
        } finally {
            $finished = true;
        }
    }

    private static function beat(int $id, int $progress, string $message): void
    {
        DB::table('background_tasks')->where('id', $id)->where('status', BackgroundTask::RUNNING)->update(['progress' => $progress, 'message' => $message, 'last_heartbeat_at' => now(), 'updated_at' => now()]);
    }

    /** Ends a task as failed and frees its lock. Does nothing to a task that already ended. */
    public static function fail(int $id, string $shown, string $technical = ''): ?string
    {
        $n = DB::table('background_tasks')->where('id', $id)->whereIn('status', [BackgroundTask::QUEUED, BackgroundTask::RUNNING])->update([
            'status' => BackgroundTask::FAILED, 'error' => mb_substr($shown, 0, 500), 'message' => __('Failed'), 'active_lock' => null, 'pid' => null,
            'finished_at' => now(), 'updated_at' => now(),
        ]);
        if ($n) {
            $task = BackgroundTask::query()->find($id);
            self::note('Background task failed', ['kind' => optional($task)->kind, 'task' => $id, 'reason' => $technical ?: $shown], $task);
        }

        return $n ? BackgroundTask::FAILED : null;
    }

    /**
     * The scheduler's once-a-minute look: runs tasks nobody picked up, fails tasks whose process is gone or silent, and tidies old files. Safe to run twice at once.
     *
     * @return array<string, int>
     */
    public static function sweep(): array
    {
        $r = ['started' => 0, 'failed_stuck' => 0, 'failed_unstarted' => 0, 'files_removed' => 0, 'rows_removed' => 0];

        // running, but the process is gone (checked when the system allows it) or has said nothing for too long
        foreach (BackgroundTask::query()->where('status', BackgroundTask::RUNNING)->get() as $t) {
            $silent = $t->last_heartbeat_at && $t->last_heartbeat_at->lt(now()->subMinutes(self::RUNNING_STUCK_MINUTES));
            $gone = $t->pid && function_exists('posix_kill') && $t->started_at && $t->started_at->lt(now()->subMinute()) && ! @posix_kill((int) $t->pid, 0) && posix_get_last_error() !== 1; // error 1 = the process exists but belongs to another user: still alive
            if ($silent || $gone) {
                if (self::fail($t->id, __('The report stopped before it was finished. Try again.'), $gone ? 'process gone' : 'silent too long')) {
                    $r['failed_stuck']++;
                }
            }
        }

        foreach (BackgroundTask::query()->where('status', BackgroundTask::QUEUED)->orderBy('id')->limit(5)->get() as $t) {
            if ($t->created_at->lt(now()->subMinutes(self::QUEUED_GIVE_UP_MINUTES))) {
                if (self::fail($t->id, __('The report was never started because the server did not pick it up. Try again, or make it from its page.'), 'not picked up')) {
                    $r['failed_unstarted']++;
                }
            } elseif ($t->created_at->lt(now()->subSeconds(20)) && self::enabled()) {
                // the process that was meant to start it did not (or could not): this one does the work
                if (self::run($t->id) !== null) {
                    $r['started']++;
                }
            }
        }

        // files are kept a week, the notes of what was done two months (the log of who asked for what stays in the activity log)
        foreach (BackgroundTask::query()->whereNotNull('result_path')->where('finished_at', '<', now()->subDays(self::KEEP_FILE_DAYS))->limit(200)->get() as $t) {
            Storage::disk('local')->delete($t->result_path);
            $t->forceFill(['result_path' => null, 'message' => __('The file was removed after :n days', ['n' => self::KEEP_FILE_DAYS])])->save();
            $r['files_removed']++;
        }
        $r['rows_removed'] = BackgroundTask::query()->whereIn('status', [BackgroundTask::DONE, BackgroundTask::FAILED, BackgroundTask::CANCELLED])->where('finished_at', '<', now()->subDays(self::KEEP_ROW_DAYS))->whereNull('result_path')->delete();

        return $r;
    }
}
