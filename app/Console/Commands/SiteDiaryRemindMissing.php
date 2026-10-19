<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\SiteDiaryNotificationRule as Rule;
use App\Services\SiteDiary\SiteDiaryNotifier;
use App\Services\SiteDiary\SiteDiaryStats;
use Illuminate\Console\Command;

/** Once a day, at the time set on the Site Diary settings page, tells the people chosen on the Site Diary workflow page which projects have no diary today. */
class SiteDiaryRemindMissing extends Command
{
    protected $signature = 'site-diary:remind-missing {--force}';

    protected $description = 'Notify about projects that require a daily Site Diary and have none for today';

    public function handle(): int
    {
        foreach (Account::pluck('id') as $accountId) {
            $rule = Rule::ruleFor($accountId, Rule::MISSING_DIARY);
            if (! SiteDiaryNotifier::missingEnabled($accountId)) {
                continue;
            }
            $due = now()->format('H:i') >= ($rule['send_time'] ?: '17:30') && (! $rule['last_run_at'] || ! $rule['last_run_at']->isToday());
            if (! $due && ! $this->option('force')) {
                continue;
            }
            $date = now()->toDateString();
            $told = SiteDiaryNotifier::missingDigest($accountId, $rule, SiteDiaryStats::missingFor($accountId, $date), $date);
            Rule::updateOrCreate(['account_id' => $accountId, 'event_key' => Rule::MISSING_DIARY], [
                'enabled' => true, 'notify_bell' => $rule['notify_bell'], 'send_email' => $rule['send_email'], 'exclude_actor' => $rule['exclude_actor'],
                'role_names' => $rule['role_names'], 'user_ids' => $rule['user_ids'], 'send_time' => $rule['send_time'], 'last_run_at' => now(),
            ]);
            $this->info("Account {$accountId}: told {$told} person(s).");
        }

        return self::SUCCESS;
    }
}
