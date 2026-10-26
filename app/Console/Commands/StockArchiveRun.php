<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Support\StockArchive\ArchiveService;
use App\Support\StockArchive\ArchiveSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class StockArchiveRun extends Command
{
    protected $signature = 'stock-archive:run {--force : make and send it now, whatever the saved day and time} {--no-email : make the entry only} {--to= : e-mail it to this address only} {--account= : one account id}';

    protected $description = 'Make the Stock Report (when the saved day and time have come) and e-mail it to the people chosen in the Stock Archive settings.';

    public function handle(): int
    {
        if (! ArchiveService::available()) {
            return 0;
        }
        foreach (Account::query()->when($this->option('account'), fn ($q, $id) => $q->where('id', $id))->get() as $account) {
            $cfg = ArchiveSettings::get($account->id);
            if (! $this->option('force') && ! ArchiveService::isDue($cfg)) {
                continue;
            }
            // claim today first, so two runs of the scheduler never make two reports
            if (! $this->option('force')) {
                ArchiveSettings::markRun($account->id, CarbonImmutable::now(ArchiveService::TZ)->toDateString());
            }
            try {
                $a = ArchiveService::generate($account->id, null);
            } catch (\Throwable $e) {
                // not made: let the next minute try again
                if (! $this->option('force')) {
                    ArchiveSettings::markRun($account->id, '');
                }
                report($e);
                $this->error('Stock report failed: ' . $e->getMessage());

                continue;
            }
            $sent = 0;
            if (! $this->option('no-email') && ($cfg['email'] || $this->option('to'))) {
                $only = $this->option('to') ? [['email' => (string) $this->option('to'), 'name' => '']] : null;
                $sent = ArchiveService::email($a, null, $only);
            }
            $this->info("Stock report {$a->reference} (account {$account->id}): " . round($a->file_size / 1024) . " KB, {$a->warehouses_count} warehouses, {$sent} e-mail(s).");
        }

        return 0;
    }
}
