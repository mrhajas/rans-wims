<?php

namespace App\Console\Commands\WimsConnect;

use App\Models\BoqMaterialCompliance;
use App\Models\User;
use App\Services\WimsConnect\CommunicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Genuinely new detection logic — BOQ compliance today is a live report
 * (ReportController::boqMaterialCompliance()), computed on page view with
 * nothing watching for a threshold crossing. This mirrors that same
 * issued-quantity-vs-baseline math (checkout_items summed against the saved
 * boq_quantity) as a scheduled check instead, and only notifies once per
 * BOQ row per bucket — a row that's already been flagged Overused won't
 * re-notify daily. Known, acceptable gap: if a row later drops back under
 * threshold and crosses again later, it won't re-fire, since nothing here
 * stores prior state to detect a *second* crossing — flagged for a future
 * revision if that turns out to matter in practice.
 */
class CheckBoqThresholds extends Command
{
    protected $signature = 'wims-connect:check-boq-thresholds';

    protected $description = 'Detect BOQ baselines whose issued quantity has crossed the Slight Overuse (>80%) or Overused (>110%) threshold, and notify via WIMS Connect.';

    public function handle(CommunicationService $communicationService): int
    {
        $rows = BoqMaterialCompliance::with(['projectWarehouse:id,name', 'item:id,name'])
            ->where('boq_quantity', '>', 0)
            ->get();

        $flagged = 0;

        foreach ($rows as $row) {
            $issuedQty = $this->issuedQuantity($row);
            $ratio = $issuedQty / $row->boq_quantity;

            $eventKey = match (true) {
                $ratio > 1.10 => 'boq-overused',
                $ratio > 0.80 => 'boq-slight-overuse',
                default => null,
            };

            if (! $eventKey || ! $row->projectWarehouse) {
                continue;
            }

            $flagged++;

            $projectManagers = User::whereHas('warehouses', fn ($q) => $q->where('warehouses.id', $row->project_warehouse_id))
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Project Manager', 'Project Managers']))
                ->where('is_active', true)
                ->get();

            if ($eventKey === 'boq-overused') {
                $admins = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Super Admin', 'Admin']))
                    ->where('is_active', true)
                    ->get();
                $projectManagers = $projectManagers->merge($admins)->unique('id');
            }

            $message = sprintf(
                "🔔 WIMS - BOQ %s\nProject: %s\nItem: %s\nIssued: %s / BOQ: %s (%d%%)",
                $eventKey === 'boq-overused' ? 'Overused' : 'Slight Overuse',
                $row->projectWarehouse->name,
                optional($row->item)->name ?? "Item #{$row->item_id}",
                number_format($issuedQty, 2),
                number_format($row->boq_quantity, 2),
                round($ratio * 100)
            );

            // One-shot per BOQ row+bucket — see class docblock for the known
            // limitation this trades off.
            // Projects workflow page (Who Gets Notified / Messages)
            \App\Services\Workflow\WorkflowMessenger::dispatch('projects', $eventKey === 'boq-overused' ? 'boq_overused' : 'boq_slight_overuse', $row, null, [
                'account_id' => $row->projectWarehouse->account_id ?? 1, 'title' => $eventKey === 'boq-overused' ? 'BOQ Overused' : 'BOQ Slight Overuse', 'message' => $message,
                'warehouse_ids' => [$row->project_warehouse_id], 'values' => ['project' => $row->projectWarehouse->name], 'dedupe' => $row->id . '|' . $eventKey,
            ]);
        }

        $this->info("Checked {$rows->count()} BOQ baseline(s), flagged {$flagged} over the notification threshold.");

        return self::SUCCESS;
    }

    protected function issuedQuantity(BoqMaterialCompliance $row): float
    {
        return (float) DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->whereNull('checkouts.deleted_at')
            ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
            ->where('checkouts.warehouse_id', $row->project_warehouse_id)
            ->where('checkout_items.item_id', $row->item_id)
            ->sum('checkout_items.quantity');
    }
}
