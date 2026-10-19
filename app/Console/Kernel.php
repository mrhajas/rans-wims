<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [];

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('stock:alert')->daily();
        $schedule->command('low-stock:send')->everyMinute()->withoutOverlapping();
        $schedule->command('acct:scan')->hourly()->withoutOverlapping();
        $schedule->command('subcontracts:scan')->dailyAt('07:30')->withoutOverlapping();
        $schedule->command('travels:remind')->dailyAt('07:20')->withoutOverlapping();
        $schedule->command('subcontracts:sweep')->hourly()->withoutOverlapping();
        $schedule->command('staff-contracts:remind')->dailyAt('06:30')->withoutOverlapping();
        $schedule->command('signatories:seed-mappings --housekeeping')->dailyAt('03:40')->withoutOverlapping();
        $schedule->command('smart-brief:clear-logs')->dailyAt('03:50')->withoutOverlapping();
        $schedule->command('wims-connect:check-overdue-issues')->dailyAt('07:30');
        $schedule->command('wims-connect:check-boq-thresholds')->dailyAt('07:45');
        // Same 365-day clean-up as `activitylog:clean`, except Site Diary history, which must never be silently deleted.
        $schedule->call(function () {
            $model = config('activitylog.activity_model', \Spatie\Activitylog\Models\Activity::class);
            $model::where('created_at', '<', now()->subDays((int) config('activitylog.delete_records_older_than_days', 365)))
                ->where('log_name', '!=', \App\Services\SiteDiary\SiteDiaryLogger::LOG_NAME)
                ->delete();
        })->name('activitylog-clean-keep-site-diary')->daily();
        $schedule->command('site-reports:auto-archive')->daily();
        $schedule->command('site-reports:remind-missing')->dailyAt('17:00');
        $schedule->command('site-reports:remind-missing --yesterday')->dailyAt('08:00');
        $schedule->command('backup:auto')->cron('0 0 */2 * *')->withoutOverlapping();
        $schedule->command('task-reminders:dispatch')->everyMinute()->withoutOverlapping();
        $schedule->command('task-manager:dispatch-due-reminders')->everyMinute()->withoutOverlapping();
        $schedule->command('assets:check-expiries')->dailyAt('07:15');
        $schedule->command('archive:notify-expiry')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('site-diary:sync-drive')->everyFiveMinutes()->withoutOverlapping(30);
        $schedule->command('site-diary:expire-media')->dailyAt('02:30')->withoutOverlapping();
        $schedule->command('site-diary:remind-missing')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('wimschat:purge')->dailyAt('03:15')->withoutOverlapping();
        $schedule->command('weather:refresh')->everyThirtyMinutes()->withoutOverlapping(20);
        $schedule->command('site-meetings:remind')->dailyAt('07:00')->withoutOverlapping();
        $schedule->command('compliance:process-expiries')->dailyAt('06:00');
        $schedule->command('digital-attendance:process')->everyFifteenMinutes()->withoutOverlapping();
        $schedule->command('wims-connect:dispatch-scheduled-bulk')->everyMinute()->withoutOverlapping();

        $dayMap = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
        $reportDay = strtolower((string) (get_settings('executive_report_day') ?: 'monday'));
        $reportTime = (string) (get_settings('executive_report_time') ?: '07:00');
        $schedule->command('executive-report:send')
            ->days($dayMap[$reportDay] ?? 1)
            ->at($reportTime)
            ->withoutOverlapping();

        if (demo()) {
            $schedule->command('data:reset')->twiceDaily(1, 13)->withoutOverlapping(5);
        }
    }
}
