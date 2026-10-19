<?php

namespace App\Console\Commands;

use App\Services\Simulation\SimulationInstaller;
use Illuminate\Console\Command;

/** Builds the simulation databases (structure only, no production users or records) and creates the first simulation user. Safe to run again. */
class SimulationInstall extends Command
{
    protected $signature = 'simulation:install
        {--admin-user=root : A database login that may create databases (used for this run only, never stored)}
        {--admin-password= : its password (or set SIM_INSTALL_DB_PASSWORD; asked for when empty and a terminal is attached)}
        {--sim-user= : the first simulation user name (omit to skip creating a user)}
        {--sim-name=Simulation Administrator : its display name}
        {--structure-only : create and fill the databases but not the first user}
        {--allow-weak-password : accept a password that does not meet the policy (the owner chose it on purpose and will change it)}';

    protected $description = 'Create and prepare the Simulation WIMS databases';

    public function handle(): int
    {
        $pass = (string) ($this->option('admin-password') ?: getenv('SIM_INSTALL_DB_PASSWORD') ?: '');
        $installer = new SimulationInstaller(['username' => (string) $this->option('admin-user'), 'password' => $pass]);
        try {
            $installer->preflight();
            $installer->createDatabases();
            $this->info('Databases ready: ' . $installer->simDb() . ' and ' . $installer->simAcctDb());
            $t = $installer->cloneStructure();
            $this->info("Tables created: main {$t['main']}, accounts {$t['accounts']}");
            $this->info('Reference data copied: ' . implode(', ', $installer->copyReference()));
            $this->line($installer->migrateSimulationOnly());
            $this->info('Empty role names copied: ' . $installer->copyRoleNames());
            $r = $installer->ensureRole();
            $this->info("Role '{$r['role']}': {$r['permissions']} abilities ({$r['added']} added)");
            if ($user = $this->option('sim-user')) {
                if (! $this->option('structure-only')) {
                    $password = (string) (getenv('SIM_ADMIN_PASSWORD') ?: ($this->input->isInteractive() ? $this->secret('Password for the simulation user (not shown)') : ''));
                    $id = $installer->provisionAdmin((string) $user, (string) $this->option('sim-name'), $password, (bool) $this->option('allow-weak-password'));
                    $this->info("Simulation user '$user' created (id $id).");
                }
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
