<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Support\StockArchive\ArchiveService;
use Illuminate\Console\Command;

class StockArchivePurge extends Command
{
    protected $signature = 'stock-archive:purge';

    protected $description = 'Remove the Stock Archive entries (and their files) older than the number of days set in the settings.';

    public function handle(): int
    {
        if (! ArchiveService::available()) {
            return 0;
        }
        foreach (Account::all() as $account) {
            $n = ArchiveService::purge($account->id);
            $n && $this->info("Stock archive (account {$account->id}): {$n} old entr" . ($n === 1 ? 'y' : 'ies') . ' removed.');
        }

        return 0;
    }
}
