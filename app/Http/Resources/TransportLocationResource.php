<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransportLocationResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'location_type' => $this->location_type,
            'address' => $this->address,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,

            'creator' => $this->whenLoaded('creator'),

            'can_edit' => $user ? $user->can('update-transport-locations') : false,
            'can_delete' => $user ? $user->can('delete-transport-locations') : false,
        ];
    }
}
