<?php

namespace App\Console\Commands;

use App\Models\BackgroundTask;
use App\Services\BackgroundTasks\TaskService;
use Illuminate\Console\Command;

class BackgroundRun extends Command
{
    protected $signature = 'background:run {id? : The task to run (without it, the oldest queued task)}';

    protected $description = 'Run one queued background task (a long read-only report) as the person who asked for it';

    public function handle(): int
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        $id = (int) ($this->argument('id') ?: BackgroundTask::query()->where('status', BackgroundTask::QUEUED)->orderBy('id')->value('id'));
        if (! $id) {
            $this->info('Nothing is waiting.');

            return self::SUCCESS;
        }
        $status = TaskService::run($id);
        $this->info($status === null ? "Task {$id} was not waiting (another process has it, or it is finished)." : "Task {$id}: {$status}");

        return $status === BackgroundTask::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
