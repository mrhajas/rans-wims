<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray($request)
    {
        return array_merge(parent::toArray($request), [
            'start_date' => $this->start_date?->format('Y-m-d'),
            'expected_end_date' => $this->expected_end_date?->format('Y-m-d'),
            'drawings' => $this->whenLoaded('attachments', fn () => $this->attachmentsGroup('drawings')->values()),
            'photos' => $this->whenLoaded('attachments', fn () => $this->attachmentsGroup('photos')->values()),
            'boq_attachments' => $this->whenLoaded('attachments', fn () => $this->attachmentsGroup('boq')->values()),
            'is_completed' => $this->is_completed,
            'completed_at' => $this->completed_at?->format('Y-m-d H:i'),
            'completed_by_name' => optional($this->completedBy)->name,
        ]);
    }
}
