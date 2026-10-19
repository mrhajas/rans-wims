<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkPackageDailyUpdateResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'work_package_id' => $this->work_package_id,
            'work_package' => $this->whenLoaded('workPackage', fn () => [
                'id' => $this->workPackage->id,
                'reference' => $this->workPackage->reference,
                'title' => $this->workPackage->title,
                'warehouse_name' => $this->workPackage->warehouse?->name,
            ]),
            'activities_completed' => $this->activities_completed,
            'progress_percent' => (float) $this->progress_percent,
            'remarks' => $this->remarks,
            'weather' => $this->weather,
            'manpower' => $this->manpower,
            'safety_observations' => $this->safety_observations,
            'inspection_results' => $this->inspection_results,
            'status' => $this->status,
            'submitter_name' => $this->whenLoaded('submitter', fn () => $this->submitter?->name),
            'approver_name' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'approved_at' => $this->approved_at,
            'approval_remarks' => $this->approval_remarks,
            'created_at' => $this->created_at,
            'can_review' => $user ? $user->can('approveUpdate', \App\Models\WorkPackage::class) : false,
        ];
    }
}
