<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\HolidayDay;
use App\Services\FeatureManager;
use App\Services\HolidayGreetings\HolidayCalendar;
use App\Services\HolidayGreetings\HolidayNotifier;
use Illuminate\Console\Command;

/**
 * Every ten minutes: makes sure this year's and next year's holidays are on the list, and reminds the Super Admins (email, SMS and bell, as the settings allow) once per holiday when
 * the set number of hours before it begins has come. A holiday on a Saturday or Sunday does not count; nothing is reminded for one that is switched off or already sent. Safe to run twice.
 */
class HolidayGreetingsRemind extends Command
{
    protected $signature = 'holiday-greetings:remind {--dry-run : List what would be sent without sending it}';

    protected $description = 'Remind the Super Admins that a holiday is coming, so they can review and send the greetings';

    public function handle(): int
    {
        if (! FeatureManager::isEnabled('holiday_greetings')) {
            $this->info('Holiday Greetings is switched off.');

            return self::SUCCESS;
        }
        $now = now(config('app.timezone'));
        foreach (Account::query()->pluck('id') as $accountId) {
            foreach ([(int) $now->year, (int) $now->year + 1] as $year) {
                HolidayCalendar::ensureYear((int) $accountId, $year);
            }
            $due = HolidayDay::withoutGlobalScope('account')->where('account_id', $accountId)->whereNull('reminder_sent_at')->whereNull('send_started_at')->where('is_enabled', true)
                ->whereBetween('date', [$now->copy()->toDateString(), $now->copy()->addDays(4)->toDateString()])->orderBy('date')->get();
            foreach ($due as $h) {
                if (! $h->counts() || $now->lt($h->reminderDueAt()) || $now->gt($h->startsAt()->copy()->endOfDay())) {
                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line('Would remind: ' . $h->name . ' (' . $h->date->format('D j M Y') . ')');
                    continue;
                }
                $r = HolidayNotifier::remind($h);
                $this->info('Reminded for ' . $h->name . ': ' . collect($r)->map(fn ($x) => $x['channel'] . ($x['ok'] ? ' ok' : ' failed'))->countBy()->map(fn ($n, $k) => "$k x$n")->implode(', '));
            }
        }

        return self::SUCCESS;
    }
}
