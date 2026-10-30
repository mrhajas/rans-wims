<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\HolidayBundle;
use App\Models\HolidayDay;
use App\Services\FeatureManager;
use App\Services\HolidayGreetings\GreetingSender;
use App\Services\HolidayGreetings\HolidayNotifier;
use Illuminate\Console\Command;

/**
 * Every minute: sends the greetings of a holiday that is set to send itself, once the set time before the holiday has come. It runs once per holiday (auto_attempted_at), only for a
 * holiday that counts and has not been sent, and never after the holiday is over. A holiday set to follow the Settings uses the Settings' time and bundle.
 */
class HolidayGreetingsAutoSend extends Command
{
    protected $signature = 'holiday-greetings:auto-send {--dry-run : List what would be sent without sending it}';

    protected $description = 'Send the greetings of the holidays that are set to send themselves';

    public function handle(): int
    {
        if (! FeatureManager::isEnabled('holiday_greetings') || ! FeatureManager::isEnabled('holiday_send')) {
            return self::SUCCESS;
        }
        $now = now(config('app.timezone'));
        foreach (Account::query()->pluck('id') as $accountId) {
            $due = HolidayDay::withoutGlobalScope('account')->where('account_id', $accountId)->whereNull('send_started_at')->whereNull('auto_attempted_at')->where('is_enabled', true)
                ->whereBetween('date', [$now->copy()->toDateString(), $now->copy()->addDays(8)->toDateString()])->orderBy('date')->get();
            foreach ($due as $h) {
                $plan = $h->autoPlan();
                if (! $plan['enabled'] || ! $h->counts() || $now->lt($h->autoSendAt()) || $now->gt($h->startsAt()->copy()->endOfDay())) {
                    continue;
                }
                $bundle = $plan['bundle_id'] ? HolidayBundle::withoutGlobalScope('account')->where('account_id', $accountId)->find($plan['bundle_id']) : null;
                if ($plan['bundle_id'] && ! $bundle) {
                    $h->forceFill(['auto_attempted_at' => $now])->save();
                    log_activity('Holiday scheduled sending skipped: the bundle no longer exists', ['holiday' => $h->name], $h, 'Holiday Greetings');
                    $this->warn($h->name . ': the bundle no longer exists, nothing sent.');
                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line('Would send: ' . $h->name . ' (' . $h->date->format('D j M Y') . ') to ' . ($bundle?->name ?? 'the whole greeting list'));
                    continue;
                }
                $h->forceFill(['auto_attempted_at' => $now])->save(); // first, so a slow send is never started twice
                $lists = GreetingSender::lists($h, $bundle?->id);
                if (! $lists['counts']['email'] && ! $lists['counts']['sms']) {
                    log_activity('Holiday scheduled sending found nobody to send to', ['holiday' => $h->name, 'greeting_list' => $bundle?->name ?? 'The whole greeting list'], $h, 'Holiday Greetings');
                    $this->warn($h->name . ': nobody to send to.');
                    continue;
                }
                $q = GreetingSender::start($h, null, null, $bundle?->id);
                log_activity('Holiday greetings sent as scheduled', ['holiday' => $h->name, 'date' => $h->date->format('Y-m-d'), 'emails' => $q['email'], 'sms' => $q['sms'], 'greeting_list' => $bundle?->name ?? 'The whole greeting list',
                    'sends_at' => $h->autoSendAt()->toDateTimeString()], $h, 'Holiday Greetings');
                HolidayNotifier::autoSent($h, $q, $bundle?->name);
                $this->info($h->name . ': ' . $q['email'] . ' emails and ' . $q['sms'] . ' SMS queued.');
            }
        }

        return self::SUCCESS;
    }
}
