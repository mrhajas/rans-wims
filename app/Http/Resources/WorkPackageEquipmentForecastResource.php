<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkPackageEquipmentForecastResource extends JsonResource
{
    public function toArray($request)
    {
        $available = $this->availableStock();
        $shortage = $this->shortage();

        return [
            'id' => $this->id,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'name' => $this->item->name,
                'code' => $this->item->code,
            ]),
            'required_quantity' => $this->required_quantity,
            'required_date' => $this->required_date,
            'notes' => $this->notes,
            'available_stock' => $available,
            'shortage' => $shortage,
            'has_shortage' => $shortage > 0,
            'creator_name' => optional($this->creator)->name,
            'created_at' => $this->created_at,
        ];
    }
}
