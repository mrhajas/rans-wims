<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MaterialReallocationResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'date_requested' => $this->date_requested?->format('Y-m-d'),
            'status' => $this->status,
            'reason' => $this->reason,
            'reason_details' => $this->reason_details,
            'remarks' => $this->remarks,
            'approval_remarks' => $this->approval_remarks,

            'source_warehouse_id' => $this->source_warehouse_id,
            'user_id' => $this->user_id,

            'submitted_on' => $this->submitted_on,
            'cleared_by_user_id' => $this->cleared_by_user_id,
            'cleared_by_name' => $this->clearedBy?->name,
            'cleared_on' => $this->cleared_on,
            'warehouse_allocated_by_user_id' => $this->warehouse_allocated_by_user_id,
            'warehouse_allocated_by_name' => $this->warehouseAllocatedBy?->name,
            'warehouse_allocated_on' => $this->warehouse_allocated_on,
            'driver_contact_id' => $this->driver_contact_id,
            'vehicle' => $this->vehicle,
            'dispatched_by_user_id' => $this->dispatched_by_user_id,
            'dispatched_by_name' => $this->dispatchedBy?->name,
            'dispatch_date_time' => $this->dispatch_date_time,
            'received_by_user_id' => $this->received_by_user_id,
            'received_by_name' => $this->receivedBy?->name,
            'received_date_time' => $this->received_date_time,
            'final_approved_by_user_id' => $this->final_approved_by_user_id,
            'final_approved_by_name' => $this->finalApprovedBy?->name,
            'final_approval_date_time' => $this->final_approval_date_time,
            'completed_on' => $this->completed_on,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,

            'can_edit' => $user ? $user->can('update', $this->resource) : false,
            'can_delete' => $user ? $user->can('delete', $this->resource) : false,
            'can_submit' => $user ? $user->can('submit', $this->resource) : false,
            'can_clear' => $user ? $user->can('clear', $this->resource) : false,
            'can_allocate' => $user ? $user->can('allocate', $this->resource) : false,
            'can_dispatch' => $user ? $user->can('dispatch', $this->resource) : false,
            'can_receive' => $user ? $user->can('receive', $this->resource) : false,
            'can_final_approve' => $user ? $user->can('finalApprove', $this->resource) : false,
            'can_request_changes' => $user ? $user->can('requestChanges', $this->resource) : false,
            'can_reject' => $user ? $user->can('reject', $this->resource) : false,
            'can_cancel' => $user ? $user->can('cancel', $this->resource) : false,
            'can_print' => $user ? $user->can('print', $this->resource) : false,
            'can_download_pdf' => $user ? $user->can('downloadPdf', $this->resource) : false,

            'user' => $this->whenLoaded('user'),
            'source_warehouse' => $this->whenLoaded('sourceWarehouse'),
            'driver_contact' => $this->whenLoaded('driverContact'),
            'items' => $this->whenLoaded('items'),
            'logs' => $this->whenLoaded('logs'),
            'attachments' => $this->whenLoaded('attachments', function () {
                return [
                    'dispatch' => $this->attachments->where('group', 'dispatch')->values(),
                    'receiving' => $this->attachments->where('group', 'receiving')->values(),
                    'other' => $this->attachments->whereNotIn('group', ['dispatch', 'receiving'])->values(),
                ];
            }),
        ];
    }
}
