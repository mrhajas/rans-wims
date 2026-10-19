<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkPackageIssueResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'work_package_id' => $this->work_package_id,
            'work_package' => $this->whenLoaded('workPackage', fn () => [
                'id' => $this->workPackage->id,
                'reference' => $this->workPackage->reference,
                'title' => $this->workPackage->title,
                'warehouse_name' => $this->workPackage->warehouse?->name,
            ]),
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'assigned_user_id' => $this->assigned_user_id,
            'assignee_name' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'deadline' => $this->deadline,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at,
            'resolver_name' => $this->whenLoaded('resolver', fn () => $this->resolver?->name),
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'attachments' => $this->whenLoaded('attachments'),
            'created_at' => $this->created_at,
            'can_update' => $user ? $user->can('update', \App\Models\WorkPackageIssue::class) : false,
            'can_delete' => $user ? $user->can('delete', \App\Models\WorkPackageIssue::class) : false,
        ];
    }
}
