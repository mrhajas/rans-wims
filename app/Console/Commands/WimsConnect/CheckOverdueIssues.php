<?php

namespace App\Console\Commands\WimsConnect;

use App\Models\IssueTransaction;
use App\Models\User;
use App\Services\WimsConnect\CommunicationService;
use Illuminate\Console\Command;

/**
 * Genuinely new detection logic (see CommunicationEventRegistry — this
 * event has no existing hook). IssueTransaction::refreshTotalsAndStatus()
 * only computes the 'overdue' status reactively, when something else
 * touches the record (a return); nothing previously re-evaluated it purely
 * because time passed. This command is that missing re-evaluation, run
 * daily, and is what actually flips a stale 'open' issue to 'overdue' in
 * the database — not just a read-only check.
 */
class CheckOverdueIssues extends Command
{
    protected $signature = 'wims-connect:check-overdue-issues';

    protected $description = 'Flag issued-material transactions that have passed their expected return date, and notify via WIMS Connect.';

    public function handle(CommunicationService $communicationService): int
    {
        $overdueCandidates = IssueTransaction::where('status', 'open')
            ->whereNotNull('expected_return_date')
            ->where('expected_return_date', '<', now()->startOfDay())
            ->with(['user:id,name', 'warehouse:id,name'])
            ->get();

        $flagged = 0;

        foreach ($overdueCandidates as $issue) {
            $issue->refreshTotalsAndStatus();

            if ($issue->status !== 'overdue') {
                continue; // a return landed between the query and here — no longer overdue
            }

            $flagged++;

            $candidates = collect();
            if ($issue->user) {
                $candidates->push($issue->user);
            }

            if ($issue->warehouse_id) {
                $siteEngineers = User::whereHas('warehouses', fn ($q) => $q->where('warehouses.id', $issue->warehouse_id))
                    ->whereHas('roles', fn ($q) => $q->whereIn('name', User::SITE_ENGINEER_ROLES))
                    ->where('is_active', true)
                    ->get();
                $candidates = $candidates->merge($siteEngineers);
            }

            $candidates = $candidates->unique('id');

            $message = sprintf(
                "🔔 WIMS - Issue Overdue\nIssue: %s\nIssued to: %s\nExpected return: %s\nPlease process the return in WIMS.",
                $issue->issue_number,
                $issue->issued_to,
                optional($issue->expected_return_date)->format('Y-m-d')
            );

            // No dedupeScope — this is a one-shot "became overdue" alert, not
            // a recurring digest, so it fires once per issue, not once per day.
            // Projects workflow page (Who Gets Notified / Messages)
            \App\Services\Workflow\WorkflowMessenger::dispatch('projects', 'issue_overdue', $issue, null, [
                'account_id' => optional($issue->warehouse)->account_id ?? 1, 'title' => 'Issue Overdue', 'message' => $message, 'owner_user_id' => (int) $issue->user_id,
                'warehouse_ids' => array_filter([$issue->warehouse_id]), 'values' => ['project' => optional($issue->warehouse)->name ?? ''], 'dedupe' => (string) $issue->id,
            ]);
        }

        $this->info("Checked {$overdueCandidates->count()} open issue(s), flagged {$flagged} as overdue.");

        return self::SUCCESS;
    }
}
