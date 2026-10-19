<?php

namespace App\Console\Commands;

use App\Services\SmartBrief\LogCleaner;
use Illuminate\Console\Command;

class SmartBriefClearLogs extends Command
{
    protected $signature = 'smart-brief:clear-logs';

    protected $description = 'Remove Smart Brief\'s own log lines older than the number of days set in Smart Brief Settings (0 = never)';

    public function handle(LogCleaner $cleaner): int
    {
        $this->info('Smart Brief log lines removed: ' . $cleaner->autoClear());

        return self::SUCCESS;
    }
}
