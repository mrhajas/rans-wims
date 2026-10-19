<?php

namespace App\Services\AiTools;

use App\Models\Transfer;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;

class TransferTool
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

        $query = Transfer::ofAccount()->withoutGlobalScope('mine')
            ->with(['toWarehouse:id,name', 'fromWarehouse:id,name', 'user:id,name']);

        if ($scope['ids'] !== null) {
            $ids = $scope['ids'];
            $query->where(fn ($q) => $q->whereIn('to_warehouse_id', $ids)->orWhereIn('from_warehouse_id', $ids));
        }

        if ($date) {
            $query->whereDate('date', $date);
        }

        $transfer = $query->latest('id')->first();

        if (! $transfer) {
            return ['found' => false, 'message' => 'No transfer found matching that criteria.'];
        }

        return [
            'found' => true,
            'reference' => $transfer->reference,
            'date' => $transfer->date,
            'from_warehouse' => optional($transfer->fromWarehouse)->name,
            'to_warehouse' => optional($transfer->toWarehouse)->name,
            'performed_by' => optional($transfer->user)->name,
            'item_count' => $transfer->items()->count(),
        ];
    }
}
