<?php

namespace App\Console\Commands;

use App\Support\Performance\QueueMode;
use Illuminate\Console\Command;

class QueueBeat extends Command
{
    protected $signature = 'queue:beat';
    protected $description = 'Marks the background worker as running (WIMS sends in the background only while this beat is fresh)';

    public function handle(): int
    {
        QueueMode::beat();

        return self::SUCCESS;
    }
}
