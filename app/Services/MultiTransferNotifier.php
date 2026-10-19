<?php

namespace App\Services;

use App\Models\MultiTransfer;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;
use Illuminate\Support\Facades\Cache;

/**
 * Hands a Multi Transfer event to its Alerts page (Who Gets Notified / Messages). Events: created, approval_needed, partly_approved, approved, cancelled,
 * received. The ordinary Transfers alerts keep firing for each transfer; these describe the request as a whole.
 */
class MultiTransferNotifier
{
    public static function fire(string $event, MultiTransfer $mt, string $title, string $message, ?int $actorId = null, array $ctx = []): bool
    {
        $mt->loadMissing(['transfers.fromWarehouse:id,name', 'toWarehouse:id,name', 'user:id,name']);
        $actor = $actorId ? User::find($actorId) : null;
        $sources = $mt->transfers->map(fn ($t) => optional($t->fromWarehouse)->name)->filter()->unique()->implode(', ');
        $refs = $mt->transfers->pluck('reference')->implode(', ');

        return WorkflowMessenger::dispatch('multi_transfers', $event, $mt, $actor, $ctx + [
            'account_id' => $mt->account_id ?? $actor?->account_id ?? 1,
            'title' => $title, 'message' => $message, 'url' => route('multi-transfers.show', $mt->id), 'owner_user_id' => (int) $mt->user_id,
            'warehouse_ids' => array_filter([$mt->to_warehouse_id]),
            'values' => ['reference' => $mt->reference, 'to' => optional($mt->toWarehouse)->name ?? '', 'sources' => $sources, 'transfers' => $refs],
            'details' => [__('Reference') => $mt->reference, __('To') => optional($mt->toWarehouse)->name, __('Sources') => $sources, __('Transfers') => $refs, __('Created by') => optional($mt->user)->name],
        ]);
    }

    /** A transfer that belongs to a Multi Transfer was approved, cancelled or received: tell the request's alerts when the WHOLE request reached a new state (once each). */
    public static function childChanged(Transfer $transfer, ?User $actor = null): void
    {
        if (! $transfer->multi_transfer_id) {
            return;
        }
        $mt = MultiTransfer::withoutGlobalScopes()->with('transfers')->find($transfer->multi_transfer_id);
        if (! $mt) {
            return;
        }
        $status = $mt->status();
        if (! in_array($status, ['partial', 'approved', 'cancelled', 'received'], true)) {
            return;
        }
        // each state is announced once per request
        if (! Cache::add('multi_transfer.' . $mt->id . '.' . $status, 1, now()->addDays(60))) {
            return;
        }
        $by = $actor ? $actor->name : '';
        [$event, $title, $text] = [
            'partial' => ['partly_approved', __('Multi Transfer partly approved'), $mt->reference . ': ' . __('some of its transfers were approved by :name and others are still waiting.', ['name' => $by])],
            'approved' => ['approved', __('Multi Transfer approved'), $mt->reference . ' ' . __('was approved. Stock has moved.')],
            'cancelled' => ['cancelled', __('Multi Transfer cancelled'), $mt->reference . ' ' . __('was cancelled by :name.', ['name' => $by])],
            'received' => ['received', __('Multi Transfer received'), $mt->reference . ' ' . __('was received at the destination.')],
        ][$status];
        self::fire($event, $mt, $title, $text, $actor?->id, ['skip_actor' => true]);
    }
}
