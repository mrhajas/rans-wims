<?php

namespace App\Console\Commands;

use App\Services\Simulation\SimulationCatalog;
use App\Services\Simulation\SimulationImporter;
use App\Support\Simulation\Environment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings ALL the business records of production into the simulation, as independent copies with provenance (see docs/simulation.md).
 * People, passwords, messages, biometrics, gateway settings, logs and system settings are never copied. One transaction: all or nothing. Safe to run again
 * (records already copied are recognised and skipped).
 */
class SimulationImportAll extends Command
{
    protected $signature = 'simulation:import-all
        {--user=category.authority : the simulation user recorded as the importer}
        {--no-mask : keep personal details (phone, email, bank and identity numbers) instead of masking them}
        {--files : also copy the attached documents into the simulation\'s own file folders}
        {--dry-run : show what would be imported and stop}';

    protected $description = 'Copy all business records from production into the simulation database';

    public function handle(): int
    {
        if (! Environment::available()) {
            $this->error('Simulation is not switched on / configured.');

            return self::FAILURE;
        }
        Environment::activate(Environment::SIMULATION);
        Environment::assertConnected();
        foreach (['mysql', 'accounts', 'mysql_production_ro', 'accounts_production_ro'] as $c) {
            DB::connection($c)->disableQueryLog();      // a long run must not keep every statement in memory
        }
        $user = DB::table('users')->where('username', $this->option('user'))->first();
        if (! $user) {
            $this->error("No simulation user '" . $this->option('user') . "'.");

            return self::FAILURE;
        }
        $imp = new SimulationImporter((int) $user->id);
        $roots = [];
        foreach (SimulationCatalog::fullImportTables() as $t) {
            try {
                $c = DB::connection(SimulationImporter::isAccounts($t) ? 'accounts_production_ro' : 'mysql_production_ro');
                $hasSoft = \Illuminate\Support\Facades\Schema::connection(SimulationImporter::isAccounts($t) ? 'accounts_production_ro' : 'mysql_production_ro')->hasColumn($t, 'deleted_at');
                $ids = $c->table($t)->when($hasSoft, fn ($q) => $q->whereNull('deleted_at'))->orderBy('id')->pluck('id')->all();
            } catch (\Throwable $e) {
                $this->warn("skipped $t: " . $e->getMessage());

                continue;
            }
            if ($ids) {
                $roots[$t] = $ids;
            }
        }
        // a document is only brought in together with the record it belongs to
        if (isset($roots['attachments'])) {
            $classes = collect(SimulationCatalog::polymorphic()['attachments']['map'])->filter(fn ($t) => isset($roots[$t]))->keys()->all();
            $roots['attachments'] = DB::connection('mysql_production_ro')->table('attachments')->whereIn('model_type', $classes)->pluck('id')->all();
            if (! $roots['attachments']) {
                unset($roots['attachments']);
            }
        }
        $opt = ['mask' => ! $this->option('no-mask'), 'files' => (bool) $this->option('files'), 'optional_all' => true, 'children' => [], 'max_rows' => 2000000];
        $this->info(count($roots) . ' kinds of record, ' . array_sum(array_map('count', $roots)) . ' records chosen.');
        try {
            if ($this->option('dry-run')) {
                $plan = $imp->planRoots('FULL IMPORT', $roots, $opt);
                $this->info('Would import ' . $plan['total'] . ' records (' . count($plan['duplicates']) . ' kinds already there).');
                foreach ($plan['blocked'] as $b) {
                    $this->error('BLOCKED: ' . $b);
                }
                foreach ($plan['warnings'] as $w) {
                    $this->warn($w);
                }
                foreach ($plan['dropped'] as $d) {
                    $this->line('  link left empty: ' . $d);
                }

                return $plan['blocked'] ? self::FAILURE : self::SUCCESS;
            }
            $r = $imp->runRoots('FULL IMPORT', $roots, $opt);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Batch {$r['reference']}: {$r['summary']['total']} records imported.");
        foreach ($r['summary']['rows'] as $t => $n) {
            $this->line(str_pad($t, 40) . $n);
        }
        if (! empty($r['summary']['files']['files'])) {
            $this->info('Documents copied: ' . $r['summary']['files']['files']);
        }

        return self::SUCCESS;
    }
}
