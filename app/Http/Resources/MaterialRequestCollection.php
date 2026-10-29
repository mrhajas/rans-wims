<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class MaterialRequestCollection extends ResourceCollection
{
    public function toArray($request)
    {
        $user = $request->user();
        $seeReminders = $user && $user->can('read-mr-reminders') && \App\Services\FeatureManager::canAccess('mr_reminders', $user);

        return $this->collection->map(function ($materialRequest) use ($user, $seeReminders) {
            return [
                'id' => $materialRequest->id,
                'reference' => $materialRequest->reference,
                'date_requested' => optional($materialRequest->date_requested)->format('Y-m-d'),
                'status' => $materialRequest->status,
                'status_label' => \App\Models\MaterialRequest::statusLabel($materialRequest->status),
                'status_by_name' => $materialRequest->status_by_name,
                'status_at' => $materialRequest->status_at,
                'history' => $materialRequest->history ?? [],
                'delivery_state' => ((int) $materialRequest->delivery_total > 0 && (int) $materialRequest->delivery_full === (int) $materialRequest->delivery_total)
                    ? 'Fully Delivered'
                    : ((int) $materialRequest->delivery_any > 0 ? 'Partially Delivered' : null),
                'approved_by_name' => $materialRequest->approved_by_name,
                'force_cleared' => (bool) $materialRequest->force_cleared,
                'can_remind' => $user ? \App\Services\MrReminders\ReminderSender::canSend($user, $materialRequest instanceof \Illuminate\Http\Resources\Json\JsonResource ? $materialRequest->resource : $materialRequest) : false,
                'can_view_reminders' => $seeReminders,
                'reminder_count' => (int) ($materialRequest->reminder_count ?? 0),
                'last_reminder_at' => $materialRequest->last_reminder_at ?? null,
                'cleared_by_user_id' => $materialRequest->cleared_by_user_id,
                'cleared_by' => $materialRequest->clearedBy ? [
                    'id' => $materialRequest->clearedBy->id,
                    'name' => $materialRequest->clearedBy->name,
                ] : null,
                'warehouse' => $materialRequest->warehouse ? [
                    'id' => $materialRequest->warehouse->id,
                    'name' => $materialRequest->warehouse->name,
                ] : null,
                'user' => $materialRequest->user ? [
                    'id' => $materialRequest->user->id,
                    'name' => $materialRequest->user->name,
                ] : null,
            ];
        })->values()->all();
    }
}
