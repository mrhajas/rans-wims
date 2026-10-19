<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class MaterialReallocationCollection extends ResourceCollection
{
    public function toArray($request)
    {
        $user = $request->user();

        return $this->collection->map(function ($item) use ($user) {
            // ResourceCollection auto-wraps each row in MaterialReallocationResource (Laravel's
            // {X}Collection -> {X}Resource naming convention). Policy checks need the raw
            // Eloquent model — passing the wrapper silently fails every ability check since no
            // policy is registered for the resource class itself.
            $materialReallocation = $item->resource;

            return [
                'id' => $materialReallocation->id,
                'reference' => $materialReallocation->reference,
                'date_requested' => optional($materialReallocation->date_requested)->format('Y-m-d'),
                'status' => $materialReallocation->status,
                'source_warehouse' => $materialReallocation->sourceWarehouse ? [
                    'id' => $materialReallocation->sourceWarehouse->id,
                    'name' => $materialReallocation->sourceWarehouse->name,
                ] : null,
                'user' => $materialReallocation->user ? [
                    'id' => $materialReallocation->user->id,
                    'name' => $materialReallocation->user->name,
                ] : null,
                'can_view' => $user ? $user->can('view', $materialReallocation) : false,
                'can_edit' => $user ? $user->can('update', $materialReallocation) : false,
                'can_delete' => $user ? $user->can('delete', $materialReallocation) : false,
            ];
        })->values()->all();
    }
}
