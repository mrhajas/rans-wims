<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkPackageLabourForecastResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
                'category' => $this->contact->category,
            ] : null),
            'trade' => $this->trade,
            'quantity' => $this->quantity,
            'duration_days' => $this->duration_days,
            'required_date' => $this->required_date,
            'notes' => $this->notes,
            'creator_name' => optional($this->creator)->name,
            'created_at' => $this->created_at,
        ];
    }
}
