<?php

namespace App\Console\Commands;

use App\Models\DailySiteReport;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;
use App\Services\SiteReportAssignments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RemindMissingSiteReports extends Command
{
    protected $signature = 'site-reports:remind-missing {--yesterday}';

    protected $description = 'Notify Site Engineers who have not submitted a site report for one of their assigned projects.';

    public function handle(): int
    {
        $isYesterday = $this->option('yesterday');
        $date = $isYesterday ? now()->subDay()->toDateString() : now()->toDateString();

        $pairs = SiteReportAssignments::pairs();
        $usersById = [];
        $notified = 0;

        foreach ($pairs as $pair) {
            try {
                $exists = DailySiteReport::query()
                    ->where('submitted_by', $pair->user_id)
                    ->where('warehouse_id', $pair->warehouse_id)
                    ->where('report_date', $date)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $usersById[$pair->user_id] ??= User::find($pair->user_id);
                $user = $usersById[$pair->user_id];
                if (! $user) {
                    continue;
                }

                // one reminder per person, project and day (also against the reminders sent before this went through the workflow page)
                $sentKey = 'site-report-reminder:' . $pair->user_id . ':' . $pair->warehouse_id . ':' . $date;
                $alreadyNotified = Cache::has($sentKey) || $user->notifications()
                    ->where('data->type', 'site_report_reminder')
                    ->where('data->warehouse_id', $pair->warehouse_id)
                    ->where('data->report_date', $date)
                    ->exists();

                if ($alreadyNotified) {
                    continue;
                }

                $message = $isYesterday
                    ? 'You missed yesterday\'s report for ' . $pair->warehouse_name . ', please submit it now.'
                    : 'You haven\'t submitted today\'s report for ' . $pair->warehouse_name . ' yet.';

                // Site Reports workflow page (Who Gets Notified / Messages): "Missing Site Report Reminder", to the engineer
                WorkflowMessenger::dispatch('site_reports', 'reminder', null, null, [
                    'account_id' => $user->account_id ?? 1, 'title' => 'Site Report Reminder', 'message' => $message,
                    'url' => route('daily-site-reports.index', [], false), 'owner_user_id' => $user->id,
                    'values' => ['project' => (string) $pair->warehouse_name, 'date' => \Carbon\Carbon::parse($date)->format('d M Y')],
                    'dedupe' => $sentKey,
                ]);
                Cache::put($sentKey, 1, now()->addDays(3));

                $notified++;
            } catch (\Throwable $e) {
                Log::error('Failed to send site report reminder', [
                    'user_id' => $pair->user_id,
                    'warehouse_id' => $pair->warehouse_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$notified} missing-report reminder(s) for {$date}.");

        return 0;
    }
}
