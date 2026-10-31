<?php

namespace App\Http\Resources;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestWorkflowStep;
use App\Policies\MaterialRequestPolicy;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialRequestResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();
        $policy = app(MaterialRequestPolicy::class);

        // Procurement (the "Procurement: Set Procedures" permission) edits procedures while the request is live.
        $canEditProcedures = $user && $policy->setProcedures($user, $this->resource);
        // Others may see the procedures read-only (the "View Procedures" permission), also once the request is final.
        $canViewOnlyProcedures = $user
            && (MaterialRequestWorkflowStep::isAllowed($user, MaterialRequestWorkflowStep::STEP_VIEW_PROCEDURES)
                || MaterialRequestWorkflowStep::isAllowed($user, MaterialRequestWorkflowStep::STEP_CLEAR))
            && in_array($this->status, MaterialRequest::procedureVisibleStatuses(), true);
        $canViewProcedures = $canEditProcedures || $canViewOnlyProcedures;
        $canConfirmDelivery = $user && $policy->confirmDelivery($user, $this->resource);
        $allowedStatuses = $user ? $policy->allowedTargetStatuses($user, $this->resource) : [];

        // A Site Engineer (Receive step, no procedure access) only ever sees the items Procurement has dealt with,
        // and never the procedure itself, from Partially Issued onwards.
        $isSiteView = $user && ! $canViewProcedures
            && MaterialRequestWorkflowStep::isAllowed($user, MaterialRequestWorkflowStep::STEP_RECEIVE)
            && in_array($this->status, [MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED, MaterialRequest::STATUS_CLEARED, MaterialRequest::STATUS_CLOSED], true);

        $items = null;
        if ($this->relationLoaded('items')) {
            $items = $this->items
                ->when($isSiteView, fn ($c) => $c->filter(fn ($i) => ! empty($i->procedure)))
                ->map(function ($item) use ($canViewProcedures) {
                    $row = $item->toArray();
                    // Site Engineers never see the procedure itself, only whether Procurement has dealt with the item.
                    $row['has_procedure'] = ! empty($item->procedure);
                    if (! $canViewProcedures) {
                        foreach (['procedure', 'procedure_contact_id', 'procedure_warehouse_id', 'procedure_note', 'procedure_contact', 'procedure_warehouse'] as $key) {
                            unset($row[$key]);
                        }
                    }

                    return $row;
                })
                ->values();
        }

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'date_requested' => $this->date_requested?->format('Y-m-d'),
            'status' => $this->status,
            'status_label' => MaterialRequest::statusLabel($this->status),
            'quick' => (bool) $this->quick,
            'reason' => $this->reason,
            'comments' => $this->comments,
            'approval_remarks' => $this->approval_remarks,
            'cleared_by_user_id' => $this->cleared_by_user_id,
            'cleared_by_name' => $this->clearedBy?->name,
            'cleared_at' => $this->cleared_at,
            'force_cleared' => (bool) $this->force_cleared,
            'warehouse_id' => $this->warehouse_id,
            'user_id' => $this->user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'can_edit' => $user ? $user->can('update', $this->resource) : false,
            'can_delete' => $user ? $user->can('delete', $this->resource) : false,
            'can_change_status' => ! empty($allowedStatuses),
            'allowed_statuses' => $allowedStatuses,
            'can_print' => $user ? $user->can('print', $this->resource) : false,
            'can_download_pdf' => $user ? $user->can('downloadPdf', $this->resource) : false,
            'can_view_procurement_procedures' => $canViewProcedures,
            'can_edit_procurement_procedures' => $canEditProcedures,
            'can_confirm_delivery' => $canConfirmDelivery,
            'is_site_view' => $isSiteView,
            'is_final' => $this->isFinal(),
            // Reminders: the bell icon on the list and the Reminder button on the request
            'can_remind' => $user ? \App\Services\MrReminders\ReminderSender::canSend($user, $this->resource) : false,
            'can_view_reminders' => $user ? ($user->can('read-mr-reminders') && \App\Services\FeatureManager::canAccess('mr_reminders', $user)) : false,
            'reminder_count' => (int) ($this->reminder_count ?? 0),
            'last_reminder_at' => $this->last_reminder_at ?? null,
            'delivery_state' => $this->relationLoaded('items') ? $this->deliveryState() : null,
            // Shown before a Super Admin clears the request (see the "delivery check" setting).
            'undelivered_items' => $this->status === MaterialRequest::STATUS_ISSUED ? $this->undeliveredItems() : [],
            'clear_delivery_mode' => MaterialRequestWorkflowStep::clearDeliveryMode(getAccountId(1)),
            'show_delivery' => in_array($this->status, [MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED, MaterialRequest::STATUS_CLEARED, MaterialRequest::STATUS_CLOSED], true),
            'procedure_options' => MaterialRequest::procedures(),
            'user' => $this->whenLoaded('user'),
            'cleared_by' => $this->whenLoaded('clearedBy'),
            'warehouse' => $this->whenLoaded('warehouse'),
            'items' => $items ?? $this->whenLoaded('items'),
            'logs' => $this->whenLoaded('logs'),
            'attachments' => $this->whenLoaded('attachments'),
        ];
    }
}
