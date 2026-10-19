<?php

namespace App\Console\Commands;

use App\Services\Accounts\AcctAlerts;
use App\Services\Accounts\AcctBalance;
use Illuminate\Console\Command;

class AcctScan extends Command
{
    protected $signature = 'acct:scan {--check : Only report balances that disagree with the ledger}';

    protected $description = 'Accounts Tracking: raise alerts that become true with time (low balances, overdue reconciliations, items waiting too long) and check balances against the ledger.';

    public function handle(AcctBalance $bal): int
    {
        if (! \Schema::connection('accounts')->hasTable('acct_accounts')) {
            $this->warn('Accounts Tracking is not installed here.');

            return self::SUCCESS;
        }
        $bad = $bal->integrity();
        foreach ($bad as $b) {
            $this->error("Balance mismatch on {$b['account']}: stored {$b['stored']}, ledger {$b['ledger']}");
        }
        if ($this->option('check')) {
            return $bad ? self::FAILURE : self::SUCCESS;
        }
        $n = AcctAlerts::sweep($bad); // a stored balance that disagrees with the ledger becomes a critical alert in the app, not just a line in the cron log
        $this->info('Scanned: ' . json_encode($n));

        return self::SUCCESS;
    }
}
