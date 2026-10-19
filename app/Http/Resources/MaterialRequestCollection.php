<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class MaterialRequestCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->map(function ($materialRequest) {
            return [
                'id' => $materialRequest->id,
                'reference' => $materialRequest->reference,
                'date_requested' => optional($materialRequest->date_requested)->format('Y-m-d'),
                'status' => $materialRequest->status,
                'status_label' => \App\Models\MaterialRequest::statusLabel($materialRequest->status),
                'status_by_name' => $materialRequest->status_by_name,
                'status_at' => $materialRequest->status_at,
                'history' => $materialRequest->history ?? [],
                'delivery_state' => ((int) $materialRequest->delivery_total > 0 && (int) $materialRequest->delivery_full === (int) $materialRequest->delivery_total)
                    ? 'Fully Delivered'
                    : ((int) $materialRequest->delivery_any > 0 ? 'Partially Delivered' : null),
                'approved_by_name' => $materialRequest->approved_by_name,
                'force_cleared' => (bool) $materialRequest->force_cleared,
                'cleared_by_user_id' => $materialRequest->cleared_by_user_id,
                'cleared_by' => $materialRequest->clearedBy ? [
                    'id' => $materialRequest->clearedBy->id,
                    'name' => $materialRequest->clearedBy->name,
                ] : null,
                'warehouse' => $materialRequest->warehouse ? [
                    'id' => $materialRequest->warehouse->id,
                    'name' => $materialRequest->warehouse->name,
                ] : null,
                'user' => $materialRequest->user ? [
                    'id' => $materialRequest->user->id,
                    'name' => $materialRequest->user->name,
                ] : null,
            ];
        })->values()->all();
    }
}
