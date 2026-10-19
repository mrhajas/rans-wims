<?php

namespace App\Console\Commands;

use App\Services\Simulation\SimulationInstaller;
use Illuminate\Console\Command;

class SimulationStatus extends Command
{
    protected $signature = 'simulation:status';

    protected $description = 'Show whether Simulation WIMS is switched on and ready';

    public function handle(): int
    {
        foreach ((new SimulationInstaller([]))->status() as $k => $v) {
            $this->line(str_pad($k, 20) . (is_bool($v) ? ($v ? 'yes' : 'no') : $v));
        }

        return self::SUCCESS;
    }
}
