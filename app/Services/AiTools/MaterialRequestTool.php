<?php

namespace App\Services\AiTools;

use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;

class MaterialRequestTool
{
    use ResolvesEntities;

    public function statusSummary(array $input, User $user): array
    {
        $warehouse = $this->resolveWarehouse($input['warehouse_name'] ?? null);
        $scope = $this->resolveWarehouseScope($warehouse);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
        }

        // MaterialRequest has no direct account_id column — whereHas('warehouse')
        // implicitly applies Warehouse's own account global scope.
        $query = MaterialRequest::whereHas('warehouse')->with(['warehouse:id,name']);

        // Same visibility scope as the Material Requests list.
        \App\Support\MaterialRequestVisibility::scopeQueryFor($query, $user);

        if ($scope['ids'] !== null) {
            $query->whereIn('warehouse_id', $scope['ids']);
        }

        $status = $input['status'] ?? null;

        if ($status) {
            $rows = (clone $query)->where('status', 'like', '%' . $status . '%')
                ->latest('id')->limit(10)->get()
                ->map(fn ($r) => [
                    'reference' => $r->reference,
                    'status' => $r->status,
                    'warehouse' => optional($r->warehouse)->name,
                    'date_requested' => optional($r->date_requested)->format('Y-m-d'),
                ])->values()->all();

            return ['found' => true, 'status_filter' => $status, 'count' => count($rows), 'requests' => $rows];
        }

        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['found' => true, 'counts_by_status' => $counts];
    }
}
