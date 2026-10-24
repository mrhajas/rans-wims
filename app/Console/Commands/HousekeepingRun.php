<?php

namespace App\Console\Commands;

use App\Support\Performance\Housekeeping;
use Illuminate\Console\Command;

class HousekeepingRun extends Command
{
    protected $signature = 'housekeeping:run';
    protected $description = 'Daily clean-up: archive old activity history, remove old weather readings, read alerts, log files and slow-query records (limits are in Settings > Performance & Data)';

    public function handle(): int
    {
        $r = Housekeeping::run();
        $this->info(json_encode($r));

        return self::SUCCESS;
    }
}
