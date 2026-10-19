<?php

namespace App\Http\Resources;

use App\Services\WorkPackageScheduleService;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkPackageResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();
        $schedule = app(WorkPackageScheduleService::class)->evaluate($this->resource);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'category' => $this->category,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
                'code' => $this->warehouse->code,
            ]),
            'building' => $this->building,
            'floor' => $this->floor,
            'zone' => $this->zone,
            'area' => $this->area,
            'engineer_id' => $this->engineer_id,
            'engineer' => $this->whenLoaded('engineer', fn () => $this->engineer ? ['id' => $this->engineer->id, 'name' => $this->engineer->name] : null),
            'foreman_id' => $this->foreman_id,
            'foreman' => $this->whenLoaded('foreman', fn () => $this->foreman ? ['id' => $this->foreman->id, 'name' => $this->foreman->name] : null),
            'subcontractor_id' => $this->subcontractor_id,
            'subcontractor' => $this->whenLoaded('subcontractor', fn () => $this->subcontractor ? ['id' => $this->subcontractor->id, 'name' => $this->subcontractor->name] : null),
            'priority' => $this->priority,
            'planned_start' => $this->planned_start?->format('Y-m-d'),
            'planned_finish' => $this->planned_finish?->format('Y-m-d'),
            'planned_duration' => $this->planned_duration,
            'dependencies' => $this->dependencies ?: [],
            'expected_progress_weight' => (float) $this->expected_progress_weight,
            'actual_progress_percent' => (float) $this->actual_progress_percent,
            'status' => $this->status,
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),

            'schedule' => $schedule,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,

            'can_edit' => $user ? $user->can('update', $this->resource) : false,
            'can_delete' => $user ? $user->can('delete', $this->resource) : false,
            'can_submit_update' => $user ? $user->can('submitUpdate', $this->resource) : false,
        ];
    }
}
