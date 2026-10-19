<?php

namespace App\Services\AiTools;

use App\Models\DailySiteReport;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AiTools\Concerns\ResolvesEntities;

class DailySiteReportTool
{
    use ResolvesEntities;

    public function latestForWarehouse(array $input, User $user): array
    {
        $warehouseName = $input['warehouse_name'] ?? null;
        $warehouse = $this->resolveWarehouse($warehouseName);

        if (! $warehouse) {
            return ['found' => false, 'message' => "No warehouse/project matching \"{$warehouseName}\" was found."];
        }

        $scope = $this->resolveWarehouseScope($warehouse);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
        }

        $report = DailySiteReport::where('warehouse_id', $warehouse->id)
            ->with('submittedBy:id,name')
            ->latest('report_date')
            ->latest('id')
            ->first();

        if (! $report) {
            return ['found' => false, 'message' => "No daily site report found for {$warehouse->name}."];
        }

        return [
            'found' => true,
            'warehouse' => $warehouse->name,
            'report_date' => optional($report->report_date)->format('Y-m-d'),
            'status' => $report->status,
            'submitted_by' => optional($report->submittedBy)->name,
        ];
    }

    public function missingToday(array $input, User $user): array
    {
        $accessible = accessible_warehouse_ids();

        $warehouses = Warehouse::ofAccount()->active()->visible()
            ->when($accessible !== null, fn ($q) => $q->whereIn('id', $accessible))
            ->get(['id', 'name']);

        $reportedIds = DailySiteReport::whereIn('warehouse_id', $warehouses->pluck('id'))
            ->whereDate('report_date', today())
            ->pluck('warehouse_id')
            ->unique();

        $missing = $warehouses->reject(fn ($w) => $reportedIds->contains($w->id))->pluck('name')->values()->all();

        return ['found' => true, 'date' => today()->format('Y-m-d'), 'count' => count($missing), 'warehouses' => $missing];
    }
}
