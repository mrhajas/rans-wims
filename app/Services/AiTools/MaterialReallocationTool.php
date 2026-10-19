<?php

namespace App\Services\AiTools;

use App\Models\MaterialReallocation;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;

class MaterialReallocationTool
{
    use ResolvesEntities;

    public function statusSummary(array $input, User $user): array
    {
        $accessible = accessible_warehouse_ids();

        // MaterialReallocation has no direct account_id column — whereHas('sourceWarehouse')
        // implicitly applies Warehouse's own account global scope.
        $query = MaterialReallocation::whereHas('sourceWarehouse');

        if ($accessible !== null) {
            $query->whereIn('source_warehouse_id', $accessible);
        }

        $status = $input['status'] ?? null;

        if ($status) {
            $rows = (clone $query)->where('status', 'like', '%' . $status . '%')
                ->latest('id')->limit(10)->get(['id', 'reference', 'status', 'source_warehouse_id', 'date_requested'])
                ->map(fn ($r) => [
                    'reference' => $r->reference ?? $r->id,
                    'status' => $r->status,
                    'date_requested' => optional($r->date_requested)->format('Y-m-d'),
                ])->values()->all();

            return ['found' => true, 'status_filter' => $status, 'count' => count($rows), 'reallocations' => $rows];
        }

        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['found' => true, 'counts_by_status' => $counts];
    }
}
