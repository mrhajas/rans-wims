<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReportController;
use App\Mail\ExecutiveIntelligenceReportMail;
use App\Models\Account;
use App\Models\ExecutiveReportLog;
use App\Models\ExecutiveReportSubscriber;
use App\Services\AiExecutiveSummaryService;
use App\Services\ExecutiveReportInsightsService;
use App\Services\ExecutiveReportMetricsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendExecutiveIntelligenceReport extends Command
{
    protected $signature = 'executive-report:send {--force : Send even if already sent for this week} {--account= : Limit to a specific account ID}';

    protected $description = 'Generate and email the weekly AI-powered Executive Intelligence Report to configured subscribers.';

    public function handle(
        ExecutiveReportMetricsService $metricsService,
        ExecutiveReportInsightsService $insightsService,
        AiExecutiveSummaryService $aiService
    ): int {
        if (! $this->option('force') && ! (bool) get_settings('executive_report_enabled')) {
            $this->info('Executive Intelligence Report is disabled in settings, skipping.');
            return 0;
        }

        $weekEnd = now()->subDay()->endOfDay();
        $weekStart = $weekEnd->copy()->subDays(6)->startOfDay();

        $accountIds = ExecutiveReportSubscriber::active()
            ->when($this->option('account'), fn ($q) => $q->where('account_id', $this->option('account')))
            ->distinct()
            ->pluck('account_id');

        if ($accountIds->isEmpty()) {
            $this->info('No active executive report subscribers configured, nothing to send.');
            return 0;
        }

        foreach ($accountIds as $accountId) {
            $this->sendForAccount((int) $accountId, $weekStart, $weekEnd, $metricsService, $insightsService, $aiService);
        }

        return 0;
    }

    protected function sendForAccount(
        int $accountId,
        \Illuminate\Support\Carbon $weekStart,
        \Illuminate\Support\Carbon $weekEnd,
        ExecutiveReportMetricsService $metricsService,
        ExecutiveReportInsightsService $insightsService,
        AiExecutiveSummaryService $aiService
    ): void {
        if (! $this->option('force') && ExecutiveReportLog::where('account_id', $accountId)
            ->where('week_start', $weekStart->toDateString())
            ->where('status', 'sent')
            ->exists()
        ) {
            $this->info("Executive report for week starting {$weekStart->toDateString()} already sent for account {$accountId}, skipping.");
            return;
        }

        $subscribers = ExecutiveReportSubscriber::active()->where('account_id', $accountId)->with('user:id,name,email,is_active')->get()
            ->filter(fn ($sub) => ! $sub->user_id || ($sub->user && $sub->user->is_active !== false && filled($sub->user->email)));
        if ($subscribers->isEmpty()) {
            return;
        }

        $companyName = optional(Account::find($accountId))->name ?? 'RANS WIMS';

        try {
            $built = app(\App\Services\ExecutiveReportBuilder::class)->generate($accountId, $weekStart, $weekEnd);
            ['metrics' => $metrics, 'insights' => $insights, 'summary' => $summary, 'cfg' => $cfg] = $built;

            $pdf = Pdf::loadView('executive-report-print', [
                'metrics' => $metrics,
                'insights' => $insights,
                'summary' => $summary,
                'cfg' => $cfg,
                'companyName' => $companyName,
                'generatedAt' => now()->format('Y-m-d H:i'),
                'logoPath' => $this->reportLogo(),
            ])->output();

            $filename = 'executive-intelligence-report-' . $weekStart->format('Ymd') . '-' . $weekEnd->format('Ymd') . '.pdf';

            $inventoryPdf = $cfg->section('inventory_pdf') ? $this->buildTotalInventoryReport($companyName) : null;
            $inventoryFilename = 'total-inventory-report-' . $weekStart->format('Ymd') . '-' . $weekEnd->format('Ymd') . '.pdf';

            $mailable = new ExecutiveIntelligenceReportMail(
                $weekStart->toDateString(),
                $weekEnd->toDateString(),
                $companyName,
                $summary,
                $metrics,
                $pdf,
                $filename,
                $inventoryPdf,
                $inventoryFilename,
                $cfg
            );

            $recipients = [];
            foreach ($subscribers as $subscriber) {
                $personalized = clone $mailable;
                // a subscriber who is a WIMS user always gets the report at their current account email
                $name = $subscriber->user->name ?? $subscriber->name;
                $email = $subscriber->user->email ?? $subscriber->email;
                $personalized->recipientName = 'Mr./Ms. ' . $name;
                Mail::to($email, $name)->send($personalized);
                $recipients[] = ['name' => $name, 'email' => $email];
            }

            ExecutiveReportLog::create([
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'recipient_count' => count($recipients),
                'recipients' => $recipients,
                'status' => 'sent',
                'sent_at' => now(),
                'account_id' => $accountId,
            ]);

            $this->info("Executive report sent to " . count($recipients) . " recipient(s) for account {$accountId}.");

            // Lightweight, separate from the full AI-generated PDF above —
            // ExecutiveReportSubscriber rows aren't WIMS user accounts (they
            // can be any name/email a Super Admin added), so they can't be
            // resolved through the matrix. This instead tells whichever WIMS
            // users opted into 'executive-intelligence-report-weekly' that
            // this week's report has gone out, with a link to review it —
            // no PDF attached here.
            \App\Services\Workflow\WorkflowMessenger::dispatch('executive_reports', 'weekly_sent', \App\Models\Account::find($accountId), null, [
                'account_id' => $accountId, 'title' => __('Executive Intelligence Report - Weekly'),
                'message' => __('This week\'s Executive Intelligence Report (:start to :end) was sent to :count subscriber(s).', ['start' => $weekStart->format('d M'), 'end' => $weekEnd->format('d M Y'), 'count' => count($recipients)]),
                'url' => route('executive-report.index'), 'dedupe' => $weekStart->format('Ymd'),
            ]);
        } catch (\Throwable $e) {
            Log::error('SendExecutiveIntelligenceReport: failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            ExecutiveReportLog::create([
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'recipient_count' => 0,
                'status' => 'failed',
                'notes' => $e->getMessage(),
                'sent_at' => now(),
                'account_id' => $accountId,
            ]);

            try {
                \App\Services\Workflow\WorkflowMessenger::dispatch('executive_reports', 'send_failed', \App\Models\Account::find($accountId), null, [
                    'account_id' => $accountId, 'title' => __('Executive Intelligence Report failed'),
                    'message' => __('The weekly Executive Intelligence Report (:start to :end) could not be sent: :why', ['start' => $weekStart->format('d M'), 'end' => $weekEnd->format('d M Y'), 'why' => \Illuminate\Support\Str::limit(strip_tags($e->getMessage()), 160)]),
                    'url' => route('executive-report.index'), 'dedupe' => 'fail|' . $weekStart->format('Ymd'),
                    'values' => ['week' => $weekStart->format('d M') . ' to ' . $weekEnd->format('d M Y'), 'reason' => \Illuminate\Support\Str::limit(strip_tags($e->getMessage()), 160)],
                ]);
            } catch (\Throwable) {
                // telling people must never hide the real failure
            }

            $this->error("Failed to send executive report for account {$accountId}: {$e->getMessage()}");
        }
    }

    /**
     * Renders the same "General Stock Report" PDF already used elsewhere in
     * the app (ReportController::generalPdf), retitled for this email as the
     * "Total Inventory Report" — an unrestricted (all-warehouse) view since
     * ReportController::generalListing(null) applies no per-user scoping.
     */
    protected function buildTotalInventoryReport(string $companyName): string
    {
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $reportController = app(ReportController::class);
        $listing = $reportController->generalListing(null);
        $grandTotal = $listing->sum('total');

        return Pdf::loadView('reports.general', [
            'title' => 'Total Inventory Report',
            'listing' => $listing,
            'grandTotal' => $grandTotal,
            'companyName' => $companyName,
            'generatedAt' => now(),
        ])->output();
    }

    protected function reportLogo(): ?string
    {
        $logo = public_path('images/logo.png');
        if (! file_exists($logo)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode(file_get_contents($logo));
    }
}
