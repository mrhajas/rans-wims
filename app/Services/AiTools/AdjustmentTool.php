<?php

namespace App\Services\AiTools;

use App\Models\Adjustment;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;

class AdjustmentTool
{
    use ResolvesEntities;

    public function last(array $input, User $user): array
    {
        $warehouse = $this->resolveWarehouse($input['warehouse_name'] ?? null);
        $scope = $this->resolveWarehouseScope($warehouse);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
        }

        $date = $this->parseDate($input['date'] ?? null);

        $query = Adjustment::ofAccount()->withoutGlobalScope('mine')->with(['warehouse:id,name', 'user:id,name']);

        if ($scope['ids'] !== null) {
            $query->whereIn('warehouse_id', $scope['ids']);
        }

        if ($date) {
            $query->whereDate('date', $date);
        }

        $adjustment = $query->latest('id')->first();

        if (! $adjustment) {
            return ['found' => false, 'message' => 'No adjustment found matching that criteria.'];
        }

        return [
            'found' => true,
            'reference' => $adjustment->reference,
            'date' => $adjustment->date,
            'type' => $adjustment->type,
            'warehouse' => optional($adjustment->warehouse)->name,
            'performed_by' => optional($adjustment->user)->name,
            'item_count' => $adjustment->items()->count(),
        ];
    }
}
