<?php

namespace App\Console\Commands;

use App\Services\Subcontracts\SubcontractAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Hourly: raise the subcontract alerts that are true now and close the ones that no longer are. */
class SubcontractSweep extends Command
{
    protected $signature = 'subcontracts:sweep';

    protected $description = 'Refresh the subcontract Alerts page';

    public function handle(): int
    {
        if (! Schema::hasTable('subcontract_alerts') || ! Schema::hasTable('subcontract_agreements')) {
            return self::SUCCESS;
        }
        $this->info('Subcontract alerts: ' . json_encode(SubcontractAlerts::sweep()));

        return self::SUCCESS;
    }
}
