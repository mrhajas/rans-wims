<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransferResource extends JsonResource
{
    public function toArray($request)
    {
        // return parent::toArray($request);
        return [
            'id'                => $this->id,
            'date'              => $this->date,
            'type'              => $this->type,
            'draft'             => $this->draft,
            'details'           => $this->details,
            'user_id'           => $this->user_id,
            'reference'         => $this->reference,
            'material_request_id' => $this->material_request_id,
            'material_request'  => $this->material_request_id ? optional($this->materialRequest)->only(['id', 'reference', 'status']) : null,
            'to_warehouse_id'   => $this->to_warehouse_id,
            'from_warehouse_id' => $this->from_warehouse_id,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
            'deleted_at'        => $this->deleted_at,
            'approved_at'       => $this->approved_at,
            'approved_by'       => $this->approved_by,
            'approval_status'   => $this->approval_status,
            'cancelled_at'      => $this->cancelled_at,
            'cancel_reason'     => $this->cancel_reason,
            'from_is_main'      => $this->relationLoaded('fromWarehouse') && $this->fromWarehouse
                ? in_array(strtolower(trim((string) $this->fromWarehouse->status)), ['main warehouse', 'main warehouses'], true)
                : false,
            'approved_by_user'  => $this->whenLoaded('approver'),
            'cancelled_by_user' => $this->whenLoaded('canceller'),
            'received_at'       => $this->received_at,
            'received_by'       => $this->received_by,
            'user'              => $this->whenLoaded('user'),
            'items'             => $this->whenLoaded('items'),
            'attachments'       => $this->whenLoaded('attachments'),
            'to_warehouse'      => $this->whenLoaded('toWarehouse'),
            'from_warehouse'    => $this->whenLoaded('fromWarehouse'),
            'received_by_user'  => $this->whenLoaded('receivedBy'),
        ];
    }
}
