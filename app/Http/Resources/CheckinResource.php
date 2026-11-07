<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CheckinResource extends JsonResource
{
    public function toArray($request)
    {
        // return parent::toArray($request);
        return [
            'id'           => $this->id,
            'date'         => $this->date,
            'draft'        => $this->draft,
            'status'       => $this->status,
            'can_approve'  => $this->isPending() && ! $this->deleted_at && \App\Models\Checkin::userCanApprove($request->user()),
            'submitted_at' => $this->submitted_at,
            'approved_at'  => $this->approved_at,
            'approved_by'  => $this->approved_by,
            'approved_by_role' => $this->approved_by_role,
            'approved_by_system' => $this->approvedBySystem(),
            'approver'     => $this->whenLoaded('approver'),
            'rejected_at'  => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'rejecter'     => $this->whenLoaded('rejecter'),
            'grn_number'   => $this->grn_number,
            'grn_generated_at' => $this->grn_generated_at,
            'grn_generated_by' => $this->grn_generated_by,
            'grn_by_system' => (bool) $this->grn_number && ! $this->grn_generated_by,
            'grn_generator' => $this->whenLoaded('grnGenerator'),
            'details'      => $this->details,
            'reference'    => $this->reference,
            'user_id'      => $this->user_id,
            'contact_id'   => $this->contact_id,
            'warehouse_id' => $this->warehouse_id,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
            'deleted_at'   => $this->deleted_at,
            'user'         => $this->whenLoaded('user'),
            'items'        => $this->whenLoaded('items'),
            'contact'      => $this->whenLoaded('contact'),
            'warehouse'    => $this->whenLoaded('warehouse'),
            'attachments'  => $this->whenLoaded('attachments'),
        ];
    }
}
