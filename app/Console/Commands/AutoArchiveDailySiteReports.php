<?php

namespace App\Console\Commands;

use App\Models\DailySiteReport;
use App\Services\DailySiteReportArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoArchiveDailySiteReports extends Command
{
    protected $signature = 'site-reports:auto-archive';

    protected $description = 'Archive daily site reports older than the configured retention period, deleting their attachments.';

    public function handle(): int
    {
        // Editable on the Settings page (Site Report Auto-Archive); the
        // config value is only the fallback for a fresh install where the
        // setting row doesn't exist yet.
        $days = (int) (get_settings('daily_site_report_auto_archive_days') ?: config('daily_site_reports.auto_archive_after_days'));

        $reports = DailySiteReport::query()
            ->where('status', '!=', DailySiteReport::STATUS_ARCHIVED)
            ->whereDate('report_date', '<', now()->subDays($days)->toDateString())
            ->get();

        $archived = 0;

        foreach ($reports as $report) {
            try {
                DailySiteReportArchiveService::archive($report, null, true);
                $archived++;
            } catch (\Throwable $e) {
                Log::error('Failed to auto-archive daily site report', [
                    'report_id' => $report->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Auto-archived {$archived} daily site report(s).");

        return 0;
    }
}
