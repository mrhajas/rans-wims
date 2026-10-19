<?php

namespace App\Services\AiTools;

use App\Models\User;
use App\Models\WorkPackage;
use App\Services\AiTools\Concerns\ResolvesEntities;

class WorkPackageTool
{
    use ResolvesEntities;

    public function statusSummary(array $input, User $user): array
    {
        $warehouse = $this->resolveWarehouse($input['warehouse_name'] ?? null);
        $scope = $this->resolveWarehouseScope($warehouse);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
        }

        $query = WorkPackage::ofAccount();

        if ($scope['ids'] !== null) {
            $query->whereIn('warehouse_id', $scope['ids']);
        }

        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'found' => true,
            'warehouse' => $warehouse->name ?? 'All warehouses (company-wide)',
            'counts_by_status' => $counts,
        ];
    }
}
