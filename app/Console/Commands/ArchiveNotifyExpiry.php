<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\ArchiveNotificationRule;
use App\Services\ArchiveNotifier;
use Illuminate\Console\Command;

/**
 * Sends the daily "expiring / expired documents" summary once the time set on the Archive Notifications page has
 * passed. Whether it is sent, to whom and in which words is decided on the Archive workflow page. Scheduled to run every 10 minutes; last_run_at stops it sending twice in a day.
 */
class ArchiveNotifyExpiry extends Command
{
    protected $signature = 'archive:notify-expiry {--force : Send now, ignoring the time and the once-a-day limit}';

    protected $description = 'Send the Archive expiring/expired documents summary at the configured time';

    public function handle(): int
    {
        foreach (Account::query()->pluck('id') as $accountId) {
            $rule = ArchiveNotificationRule::ruleFor($accountId, ArchiveNotificationRule::EXPIRY_DIGEST);
            if (! $rule || ! ArchiveNotifier::digestEnabled($accountId)) {
                continue;
            }

            $due = now()->format('H:i') >= ($rule['send_time'] ?: '08:00')
                && (! $rule['last_run_at'] || ! $rule['last_run_at']->isToday());

            if (! $due && ! $this->option('force')) {
                continue;
            }

            $sent = ArchiveNotifier::expiryDigest($accountId, $rule);

            ArchiveNotificationRule::query()->updateOrCreate(
                ['account_id' => $accountId, 'event_key' => ArchiveNotificationRule::EXPIRY_DIGEST],
                ['last_run_at' => now()] + ['enabled' => $rule['enabled'], 'notify_bell' => $rule['notify_bell'], 'send_email' => $rule['send_email'], 'role_names' => $rule['role_names'], 'user_ids' => $rule['user_ids'], 'exclude_actor' => $rule['exclude_actor'], 'send_time' => $rule['send_time'], 'lead_days' => $rule['lead_days']]
            );

            $this->info("Account {$accountId}: summary sent to {$sent} user(s).");
        }

        return self::SUCCESS;
    }
}
