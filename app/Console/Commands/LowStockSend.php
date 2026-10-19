<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\LowStock\LowStockConfig;
use App\Services\LowStock\LowStockService;
use Illuminate\Console\Command;

class LowStockSend extends Command
{
    protected $signature = 'low-stock:send {--force : send now, ignoring the saved time and days} {--to= : send one sample email to this address only} {--account= : limit to one account id}';

    protected $description = 'Send the Low Stock Alert (email / text message) when its saved time of day has arrived.';

    public function handle(): int
    {
        if (! LowStockService::available()) {
            return 0;
        }
        foreach (Account::query()->when($this->option('account'), fn ($q, $id) => $q->where('id', $id))->get() as $account) {
            $cfg = LowStockConfig::get($account->id);
            $sample = (bool) $this->option('to');
            if (! $this->option('force') && ! $sample && ! LowStockService::isDue($cfg)) {
                continue;
            }
            $r = LowStockService::send($account->id, (bool) $this->option('force'), $this->option('to') ?: null, $sample);
            $this->info("Low stock alert (account {$account->id}): {$r['recipients']} recipient(s), {$r['emails']} email(s), {$r['sms']} text message(s), {$r['items']} item(s).");
        }

        return 0;
    }
}
