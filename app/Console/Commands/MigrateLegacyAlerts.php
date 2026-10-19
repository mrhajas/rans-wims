<?php

namespace App\Console\Commands;

use App\Support\Workflow\LegacyAlertMigrator;
use Illuminate\Console\Command;

class MigrateLegacyAlerts extends Command
{
    protected $signature = 'wims:migrate-legacy-alerts {--force : Overwrite workflow settings that are already saved}';

    protected $description = 'Copy the Notification Center, Communication Rules, Email Alerts matrix and Salary notification rules into the module workflow pages';

    public function handle(): int
    {
        $r = LegacyAlertMigrator::run((bool) $this->option('force'));
        $this->info($r['events'] . ' event(s) copied into the workflow pages.');
        foreach ($r['notes'] as $n) {
            $this->warn($n);
        }

        return self::SUCCESS;
    }
}
