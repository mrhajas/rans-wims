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
        // "Send in the background" (Settings > Performance & Data): the beat tells WIMS the worker is alive; the worker itself runs for ~55 seconds each minute, in the
        // background so the other scheduled tasks never wait for it
        \App\Support\Performance\QueueMode::apply();
        $schedule->command('queue:beat')->everyMinute();
        $schedule->command('queue:work --max-time=55 --sleep=2 --tries=3 --timeout=50')->everyMinute()->withoutOverlapping(2)->runInBackground();
        $schedule->command('archive:export-run')->everyMinute()->withoutOverlapping(3)->runInBackground();
        $schedule->command('housekeeping:run')->dailyAt('02:10')->withoutOverlapping();
        $schedule->command('stock:alert')->daily();
        // Stock Archive: the day and time are settings, so the check runs every minute and acts only when they have come; old entries go once a day
        $schedule->command('stock-archive:run')->everyMinute()->withoutOverlapping(10);
        $schedule->command('stock-archive:purge')->dailyAt('03:25')->withoutOverlapping();
        $schedule->command('low-stock:send')->everyMinute()->withoutOverlapping();
        $schedule->command('acct:scan')->hourly()->withoutOverlapping();
        $schedule->command('subcontracts:scan')->dailyAt('07:30')->withoutOverlapping();
        $schedule->command('travels:remind')->dailyAt('07:20')->withoutOverlapping();
        $schedule->command('visitor-logs:watch')->everyThirtyMinutes()->withoutOverlapping();
        $schedule->command('subcontracts:sweep')->hourly()->withoutOverlapping();
        $schedule->command('staff-contracts:remind')->dailyAt('06:30')->withoutOverlapping();
        $schedule->command('signatories:seed-mappings --housekeeping')->dailyAt('03:40')->withoutOverlapping();
        $schedule->command('smart-brief:clear-logs')->dailyAt('03:50')->withoutOverlapping();
        $schedule->command('wims-connect:check-overdue-issues')->dailyAt('07:30');
        $schedule->command('wims-connect:check-boq-thresholds')->dailyAt('07:45');
        // Activity history is no longer deleted after a year: housekeeping:run (above) moves what is older than the number of months set in
        // Settings > Performance & Data into the activity archive, where it stays and can be downloaded.
        $schedule->command('site-reports:auto-archive')->daily();
        $schedule->command('site-reports:remind-missing')->dailyAt('17:00');
        $schedule->command('site-reports:remind-missing --yesterday')->dailyAt('08:00');
        // every hour it asks whether the interval chosen on the Backup page (8 hours to 15 days) has passed
        $schedule->command('backup:auto')->hourly()->withoutOverlapping();
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
