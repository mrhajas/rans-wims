<?php

namespace App\Services\AiTools;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\AiTools\Concerns\ResolvesEntities;

class WarehouseTool
{
    use ResolvesEntities;

    public function info(array $input, User $user): array
    {
        $warehouseName = $input['warehouse_name'] ?? null;

        if ($warehouseName) {
            $warehouse = $this->resolveWarehouse($warehouseName);

            if (! $warehouse) {
                return ['found' => false, 'message' => "No warehouse/project matching \"{$warehouseName}\" was found."];
            }

            $scope = $this->resolveWarehouseScope($warehouse);

            if ($scope['denied']) {
                return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
            }

            return [
                'found' => true,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'status' => $warehouse->status,
                'active' => (bool) $warehouse->active,
                'start_date' => optional($warehouse->start_date)->format('Y-m-d'),
                'expected_end_date' => optional($warehouse->expected_end_date)->format('Y-m-d'),
            ];
        }

        $accessible = accessible_warehouse_ids();

        $warehouses = Warehouse::ofAccount()
            ->visible()
            ->when($accessible !== null, fn ($q) => $q->whereIn('id', $accessible))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status', 'active'])
            ->map(fn ($w) => ['name' => $w->name, 'code' => $w->code, 'status' => $w->status, 'active' => (bool) $w->active])
            ->values()->all();

        return ['found' => true, 'count' => count($warehouses), 'warehouses' => $warehouses];
    }
}
