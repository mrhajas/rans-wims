<?php

namespace App\Console\Commands;

use App\Models\SiteMeeting;
use App\Services\FeatureManager;
use App\Services\SiteMeetings\SiteMeetingNotifier;
use App\Services\SiteMeetings\SiteMeetingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Daily: tells everyone invited to a Scheduled meeting that it is today, and (setting) that it is coming up in N days.
 * Safe to run twice: a person is reminded about the same meeting on the same day only once.
 */
class SiteMeetingsRemind extends Command
{
    protected $signature = 'site-meetings:remind {--dry-run : List what would be sent without sending it}';

    protected $description = 'Remind the people invited to today\'s and upcoming site meetings';

    public function handle(): int
    {
        if (! FeatureManager::isEnabled('site_meetings') || ! FeatureManager::isEnabled('site_meetings_reminders')) {
            $this->info('Site Meetings reminders are switched off.');

            return self::SUCCESS;
        }

        $sent = 0;
        $accounts = SiteMeeting::withoutGlobalScope('account')->where('status', SiteMeeting::SCHEDULED)->where('meeting_date', '>=', now()->toDateString())->distinct()->pluck('account_id');
        foreach ($accounts as $accountId) {
            $settings = app(SiteMeetingSettings::class)->forAccount((int) $accountId);
            if (! $settings->flag('reminders_enabled')) {
                continue;
            }
            $ahead = max(0, (int) $settings->get('reminder_days_before'));
            $dates = array_unique([now()->toDateString(), now()->addDays($ahead)->toDateString()]);

            $meetings = SiteMeeting::withoutGlobalScope('account')->where('account_id', $accountId)->where('status', SiteMeeting::SCHEDULED)
                ->whereIn('meeting_date', $dates)->with('warehouse:id,name')->get();
            foreach ($meetings as $meeting) {
                $isToday = $meeting->meeting_date->isToday();
                $key = 'site-meeting-remind:'.$meeting->id.':'.now()->toDateString().':'.($isToday ? 'today' : 'ahead');
                if (Cache::has($key)) {
                    continue;
                }
                $when = $isToday ? 'is today' : ($meeting->meeting_date->isTomorrow() ? 'is tomorrow' : 'is on '.$meeting->meeting_date->format('D, d M'));
                if ($this->option('dry-run')) {
                    $this->line($meeting->reference.' - '.$meeting->display_title.' '.$when.' → '.count(SiteMeetingNotifier::involved($meeting)).' people');
                    continue;
                }
                $sent += SiteMeetingNotifier::reminder($meeting, $when);
                Cache::put($key, 1, now()->addDay());
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run finished.' : "Reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
