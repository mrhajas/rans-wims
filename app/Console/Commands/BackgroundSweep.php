<?php

namespace App\Console\Commands;

use App\Services\BackgroundTasks\TaskService;
use Illuminate\Console\Command;

class BackgroundSweep extends Command
{
    protected $signature = 'background:sweep';

    protected $description = 'Run background tasks nobody picked up, fail stuck ones (freeing their lock) and remove old result files. Every minute from the scheduler.';

    public function handle(): int
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        $r = TaskService::sweep();
        if (array_sum($r)) {
            $this->info(json_encode($r));
        }

        return self::SUCCESS;
    }
}
