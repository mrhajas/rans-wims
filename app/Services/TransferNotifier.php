<?php

namespace App\Services;

use App\Models\Transfer;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Hands a Transfer event to the Transfers workflow page's settings (Who Gets Notified / Messages). Events: created, updated, deleted,
 * restored, approval_needed, approved, cancelled, dispatched, received, done.
 */
class TransferNotifier
{
    public static function fire(string $event, Transfer $transfer, string $title, string $message, ?int $actorId = null, array $ctx = []): bool
    {
        $transfer->loadMissing(['fromWarehouse:id,name,status', 'toWarehouse:id,name,status', 'user:id,name', 'items']);
        $actor = $actorId ? User::find($actorId) : null;

        return WorkflowMessenger::dispatch('transfers', $event, $transfer, $actor, $ctx + [
            'account_id' => $transfer->account_id ?? $actor?->account_id ?? 1,
            'title' => $title, 'message' => $message, 'url' => route('transfers.show', $transfer->id), 'owner_user_id' => (int) $transfer->user_id,
            'warehouse_ids' => array_filter([$transfer->to_warehouse_id]),
            'values' => [
                'reference' => $transfer->reference, 'from' => optional($transfer->fromWarehouse)->name ?? '', 'to' => optional($transfer->toWarehouse)->name ?? '',
                'itemscount' => (string) $transfer->items->count(), 'creator' => optional($transfer->user)->name ?? '',
            ],
            'details' => [
                __('Reference') => $transfer->reference, __('From') => optional($transfer->fromWarehouse)->name, __('To') => optional($transfer->toWarehouse)->name,
                __('Created by') => optional($transfer->user)->name,
            ],
        ]);
    }

    public static function route(Transfer $transfer): string
    {
        $transfer->loadMissing(['fromWarehouse:id,name,status', 'toWarehouse:id,name,status']);

        return optional($transfer->fromWarehouse)->name . ' → ' . optional($transfer->toWarehouse)->name;
    }
}
