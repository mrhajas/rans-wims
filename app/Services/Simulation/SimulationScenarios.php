<?php

namespace App\Services\Simulation;

use App\Support\Simulation\Environment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The scenarios of the simulation (see the Scenarios page). Only meaningful inside the simulation. */
class SimulationScenarios
{
    /** What the always-visible Simulation marker shows: set by the server for a signed-in simulation session. */
    public static function banner(): array
    {
        $name = null;
        try {
            if (Environment::isSimulation() && Schema::hasTable('simulation_scenarios')) {
                $name = DB::table('simulation_scenarios')->where('is_current', 1)->value('name');
            }
        } catch (\Throwable) {
        }

        return ['mode' => 'simulation', 'label' => 'Simulation', 'scenario' => $name];
    }
}
