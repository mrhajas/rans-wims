<?php

namespace App\Console\Commands;

use App\Support\Performance\RequestStats;
use Illuminate\Console\Command;

/** Adds the recorded request times up into the hourly figures shown on System Health (see App\Support\Performance\RequestStats). Safe to run at any time. */
class PerfAggregate extends Command
{
    protected $signature = 'perf:aggregate';

    protected $description = 'Add the recorded page and API response times up for System Health';

    public function handle(): int
    {
        $this->info(RequestStats::aggregate() . ' request(s) added up.');

        return self::SUCCESS;
    }
}
