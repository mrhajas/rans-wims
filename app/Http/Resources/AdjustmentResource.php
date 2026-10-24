<?php

namespace App\Http\Resources;

use App\Models\Adjustment;
use Illuminate\Http\Resources\Json\JsonResource;

class AdjustmentResource extends JsonResource
{
    public function toArray($request)
    {
        // return parent::toArray($request);
        return [
            'id'           => $this->id,
            'date'         => $this->date,
            'type'         => $this->type,
            'draft'        => $this->draft,
            'details'      => $this->details,
            'user_id'      => $this->user_id,
            'approved_by'  => $this->approved_by,
            'approved_at'  => $this->approved_at,
            'approved_by_role' => $this->approved_by_role,
            'rejected_by'  => $this->rejected_by,
            'rejected_at'  => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'status'       => $this->status,
            'approval_locked' => $this->resource->approvalLocked(),
            'approval_locked_message' => $this->resource->approvalLocked() ? Adjustment::reversalBlockedMessage() : null,
            'can_change_status' => ! $this->deleted_at && (bool) optional($request->user())->hasRole('Super Admin'),
            'can_approve'  => $this->resource->isPending() && ! $this->deleted_at && Adjustment::userCanApprove($request->user()),
            'reference'    => $this->reference,
            'items_count'  => $this->when(isset($this->items_count), fn () => (int) $this->items_count),
            'qty_total'    => $this->when(isset($this->qty_total), fn () => (float) $this->qty_total),
            'warehouse_id' => $this->warehouse_id,
            'extra_attributes' => $this->extra_attributes,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
            'deleted_at'   => $this->deleted_at,
            'user'         => $this->whenLoaded('user'),
            'approver'     => $this->whenLoaded('approver'),
            'rejecter'     => $this->whenLoaded('rejecter'),
            'items'        => $this->whenLoaded('items'),
            'warehouse'    => $this->whenLoaded('warehouse'),
            'attachments'  => $this->whenLoaded('attachments'),
        ];
    }
}
