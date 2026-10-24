<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SuperAdjustmentResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'date' => $this->date,
            'status' => $this->status,
            'warehouse_id' => $this->warehouse_id,
            'reason' => $this->reason,
            'details' => $this->details,
            'remarks' => $this->remarks,
            'user_id' => $this->user_id,
            'approved_by_user_id' => $this->approved_by_user_id,
            'approved_at' => $this->approved_at,
            'approved_by_role' => $this->approved_by_role,
            // Approved by the very person who submitted it (a Super Admin's own count is approved on submission)
            'self_approved' => $this->status === 'Approved' && (int) $this->approved_by_user_id === (int) $this->user_id,
            'rejected_by_user_id' => $this->rejected_by_user_id,
            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'created_addition_adjustment_id' => $this->created_addition_adjustment_id,
            'created_subtraction_adjustment_id' => $this->created_subtraction_adjustment_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,

            'items_count' => $this->when(isset($this->items_count), fn () => (int) $this->items_count),
            'raised_total' => $this->when(isset($this->raised_total), fn () => (float) $this->raised_total),
            'lowered_total' => $this->when(isset($this->lowered_total), fn () => (float) $this->lowered_total),
            'adjustment_session_id' => $this->adjustment_session_id,
            'session' => $this->whenLoaded('adjustmentSession', fn () => $this->adjustmentSession ? ['id' => $this->adjustmentSession->id, 'reference' => $this->adjustmentSession->reference, 'status' => $this->adjustmentSession->status] : null),
            'warehouse' => $this->whenLoaded('warehouse'),
            'user' => $this->whenLoaded('user'),
            'approver' => $this->whenLoaded('approver'),
            'rejecter' => $this->whenLoaded('rejecter'),
            // phase 1 / phase 2 of the approval for the list: who gave each and when (done = false while still missing)
            'phases' => $this->whenLoaded('approvals', fn () => collect(range(1, max(\App\Models\SuperAdjustment::requiredApprovals(), $this->approvals->count())))->map(function ($p) {
                $a = $this->approvals->firstWhere('phase', $p);

                return ['phase' => $p, 'done' => (bool) $a, 'user' => optional(optional($a)->user)->name, 'at' => optional(optional($a)->approved_at)->toDateTimeString(), 'note' => optional($a)->note];
            })->values()),
            'items' => $this->whenLoaded('items'),
            'attachments' => $this->whenLoaded('attachments'),
            'addition_adjustment' => $this->whenLoaded('additionAdjustment'),
            'subtraction_adjustment' => $this->whenLoaded('subtractionAdjustment'),

            'can_edit' => $user ? (($this->status === 'Draft' && ((int) $this->user_id === (int) $user->id || $user->can('update-super-adjustments'))) || $user->hasRole('Super Admin')) : false,
            'approval_locked' => $this->resource->approvalLocked(),
            'approval_locked_message' => $this->resource->approvalLocked() ? \App\Models\Adjustment::reversalBlockedMessage(__('Super Adjustment')) : null,
            'can_change_status' => $user ? ! $this->deleted_at && $user->hasRole('Super Admin') : false,
            'can_delete' => $user ? ($this->status === 'Draft' && ((int) $this->user_id === (int) $user->id || $user->can('delete-super-adjustments'))) : false,
            // waiting for approval, allowed to approve (two approvals: Super Admins only), and not the person who already gave the first approval
            'can_approve' => $user ? ($this->resource->isOpen()
                && (\App\Models\SuperAdjustment::requiredApprovals() === 2 ? $user->hasRole('Super Admin') : ($user->hasRole('Super Admin') || $user->can('approve-super-adjustments')))
                && ! ($this->resource->relationLoaded('approvals') && $this->resource->approvals->contains('user_id', $user->id))) : false,
            'i_approved_phase1' => $user && $this->resource->relationLoaded('approvals') ? $this->resource->approvals->contains('user_id', $user->id) : false,
            'required_approvals' => \App\Models\SuperAdjustment::requiredApprovals(),
            'can_print' => $user ? $user->can('print-super-adjustments') : false,
        ];
    }
}
