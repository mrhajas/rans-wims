<?php

namespace App\Console\Commands;

use App\Services\Simulation\SimulationInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** After a deployment: bring the simulation up to date with production (new tables and columns, new abilities) without touching any simulation data. */
class SimulationSync extends Command
{
    protected $signature = 'simulation:sync';

    protected $description = 'Apply new migrations to the simulation databases and copy new abilities into the simulation role';

    public function handle(): int
    {
        if (! \App\Support\Simulation\Environment::available()) {
            $this->error('Simulation is not switched on / configured.');

            return self::FAILURE;
        }
        $sim = DB::connection('mysql_simulation');
        // new abilities
        $have = $sim->table('permissions')->pluck('name')->all();
        $new = DB::connection('mysql')->table('permissions')->whereNotIn('name', $have)->get();
        foreach ($new as $p) {
            $sim->table('permissions')->insert((array) $p);
        }
        $this->info('New abilities copied: ' . $new->count());
        $installer = new SimulationInstaller([]);
        $this->info('Empty role names copied: ' . $installer->copyRoleNames());
        $r = $installer->ensureRole();
        $this->info('Roles added to full-access accounts: ' . app(\App\Services\Simulation\SimulationAccounts::class)->syncRoles());
        $this->info("Role '{$r['role']}': {$r['permissions']} abilities ({$r['added']} added)");
        Artisan::call('migrate', ['--database' => 'mysql_simulation', '--path' => 'database/migrations/simulation', '--force' => true]);
        $this->line(trim(Artisan::output()));
        // the ordinary migrations that production has run since, applied to the simulation database (its migrations table was cloned, so only the new ones run)
        Artisan::call('migrate', ['--database' => 'mysql_simulation', '--force' => true]);
        $this->line(trim(Artisan::output()));

        return self::SUCCESS;
    }
}
