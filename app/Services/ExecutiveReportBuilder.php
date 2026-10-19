<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Carbon;

/** Computes the Executive Intelligence Report for an account the way Report Content is set up (used by the weekly send and the preview). */
class ExecutiveReportBuilder
{
    public function __construct(
        protected ExecutiveReportMetricsService $metrics,
        protected ExecutiveReportInsightsService $insights,
        protected AiExecutiveSummaryService $ai,
    ) {
    }

    /** @return array{cfg: ExecutiveReportConfig, metrics: array, insights: array, summary: array, companyName: string} */
    public function generate(int $accountId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $cfg = ExecutiveReportConfig::forAccount($accountId);
        $companyName = optional(Account::find($accountId))->name ?? 'RANS WIMS';

        $metrics = $this->metrics->build($accountId, $weekStart, $weekEnd, $cfg);
        $insights = $this->insights->build($accountId, $weekStart, $weekEnd, $cfg);
        [$metrics, $insights] = $cfg->scrub($metrics, $insights);

        // the written parts are only produced when at least one of them is switched on (no AI call is spent otherwise)
        $wantsProse = $cfg->section('summary') || $cfg->section('notable_events') || $cfg->section('actions');
        $summary = $wantsProse
            ? $this->ai->summarize($metrics, $insights, $companyName, $cfg->excludedLabels())
            : ['executive_summary' => '', 'notable_events' => [], 'action_items' => []];
        if (! $cfg->section('summary')) {
            $summary['executive_summary'] = '';
        }
        if (! $cfg->section('notable_events')) {
            $summary['notable_events'] = [];
        }
        if (! $cfg->section('actions')) {
            $summary['action_items'] = [];
        }

        return compact('cfg', 'metrics', 'insights', 'summary', 'companyName');
    }
}
