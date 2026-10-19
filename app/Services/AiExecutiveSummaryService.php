<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns the pre-computed weekly metrics/insights into the prose sections of
 * the Executive Intelligence Report: a short executive summary paragraph and
 * a handful of "notable events" bullets, written in the voice of a senior
 * operations manager briefing leadership.
 *
 * Deliberately narrow scope: the model is only ever asked to phrase numbers
 * we already computed — it is never given room to invent a figure. If the
 * API call fails for any reason, a plain templated fallback is used instead
 * so the report always sends, AI outage or not.
 */
class AiExecutiveSummaryService
{
    /** @param list<string> $excluded names of report sections the report leaves out on purpose */
    public function summarize(array $metrics, array $insights, string $companyName, array $excluded = []): array
    {
        $apiKey = config('services.anthropic.key');

        if (! $apiKey) {
            return $this->fallback($metrics, $insights);
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.model'),
                    'max_tokens' => 1024,
                    'messages' => [
                        ['role' => 'user', 'content' => $this->buildPrompt($metrics, $insights, $companyName, $excluded)],
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('AiExecutiveSummaryService: Anthropic API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $this->fallback($metrics, $insights);
            }

            $text = data_get($response->json(), 'content.0.text', '');
            $parsed = json_decode($this->extractJson($text), true);

            if (! is_array($parsed) || ! isset($parsed['executive_summary'])) {
                return $this->fallback($metrics, $insights);
            }

            return [
                'executive_summary' => (string) $parsed['executive_summary'],
                'notable_events' => array_map('strval', (array) ($parsed['notable_events'] ?? [])),
                'action_items' => array_map('strval', (array) ($parsed['action_items'] ?? [])),
            ];
        } catch (\Throwable $e) {
            Log::error('AiExecutiveSummaryService: failed to generate summary', ['error' => $e->getMessage()]);

            return $this->fallback($metrics, $insights);
        }
    }

    protected function buildPrompt(array $metrics, array $insights, string $companyName, array $excluded = []): string
    {
        $data = json_encode(['metrics' => $metrics, 'insights' => $insights], JSON_PRETTY_PRINT);
        $left = $excluded ? 'This report deliberately leaves out: '.implode(', ', $excluded).'. Never mention or hint at those.' : '';

        return <<<PROMPT
            You are a senior operations manager at {$companyName}, a construction and warehouse management company. Write the prose sections of your weekly executive briefing for {$metrics['week_start']} to {$metrics['week_end']}.

            You are given ALREADY-COMPUTED, accurate structured data below. Your job is ONLY to synthesize and phrase these exact figures into natural, professional prose, never invent, estimate, or round differently than what is given. If a section has no data, do not mention it.

            {$left}

            Data:
            {$data}

            Respond with ONLY a JSON object (no markdown fences, no commentary) in this exact shape:
            {"executive_summary": "4-6 sentence paragraph in first person plural (\\"we\\"), confident and direct, the way an experienced ops manager briefs leadership", "notable_events": ["short bullet 1", "short bullet 2", "..."], "action_items": ["short recommended action 1", "..."]}

            The notable_events array should contain 2-5 short bullets (under 25 words each), only the things that most need management's attention this week, drawn strictly from the data above.

            The action_items array should contain 2-4 short, concrete, decision-ready recommendations (under 25 words each) that follow directly from the data, e.g. who to follow up with, what to investigate, what to approve or reorder. Do not recommend anything not supported by the data. If nothing in the data warrants a specific action, return an empty array rather than inventing generic advice.
            PROMPT;
    }

    protected function extractJson(string $text): string
    {
        $text = trim($text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false) {
            return '{}';
        }

        return substr($text, $start, $end - $start + 1);
    }

    protected function fallback(array $metrics, array $insights): array
    {
        $parts = [];
        if (isset($metrics['transaction_counts']['checkins']['this_week'], $metrics['transaction_counts']['checkouts']['this_week'])) {
            $parts[] = sprintf('%d checkins and %d checkouts', $metrics['transaction_counts']['checkins']['this_week'], $metrics['transaction_counts']['checkouts']['this_week']);
        }
        $summary = $parts ? 'This week recorded '.implode(' and ', $parts) : 'Here is this week\'s briefing';
        if (isset($metrics['active_projects'])) {
            $summary .= sprintf(' across %d active project(s)', $metrics['active_projects']);
        }
        if (isset($metrics['consumption_value']['this_week'])) {
            $summary .= sprintf(', with an estimated %s consumed in materials', number_format($metrics['consumption_value']['this_week'], 2));
        }
        $summary .= '.';

        $events = [];
        $actions = [];

        if (! empty($insights['material_requests_pipeline']['stale'])) {
            $count = count($insights['material_requests_pipeline']['stale']);
            $events[] = "{$count} material request(s) have been pending for over 5 days.";
            $actions[] = "Follow up on the {$count} material request(s) stuck over 5 days to unblock site progress.";
        }
        if (! empty($insights['site_report_compliance']['non_compliant_sites'])) {
            $count = count($insights['site_report_compliance']['non_compliant_sites']);
            $events[] = "{$count} site(s) missed their daily report this week.";
            $actions[] = "Remind the site engineers behind on daily reports to bring compliance back up.";
        }
        if (! empty($insights['boq_variance_flags'])) {
            $count = count($insights['boq_variance_flags']);
            $events[] = "{$count} project(s) have exceeded their BOQ material baseline.";
            $actions[] = 'Review the project(s) exceeding BOQ baseline for cost overrun risk.';
        }
        if (! empty($insights['stock_health']['out_of_stock_count'])) {
            $count = $insights['stock_health']['out_of_stock_count'];
            $events[] = "{$count} item(s) are completely out of stock at one or more warehouses.";
            $actions[] = 'Prioritize restocking the out-of-stock items flagged below to avoid site delays.';
        }
        if (! empty($insights['adjustment_watch'])) {
            $actions[] = 'Investigate the warehouse(s) with an unusual spike in damage adjustments this week.';
        }

        return ['executive_summary' => $summary, 'notable_events' => $events, 'action_items' => $actions];
    }
}
