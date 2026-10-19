<?php

namespace App\Services\ExternalStock;

use App\Models\ExternalStockMovement;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/** Hands an External Stock event to its Alerts page (who is told, on which channel). Never throws: an alert must not stop a movement being saved. */
class ExternalStockNotifier
{
    public static function fire(string $event, ExternalStockMovement $m, ?User $actor = null, string $message = ''): void
    {
        try {
            $m->loadMissing(['contact:id,name,phone', 'warehouse:id,name', 'user:id,name']);
            $driver = $m->driver_name ?: ($m->driver_contact_id ? optional(\App\Models\Contact::find($m->driver_contact_id))->name : '');
            $plate = $m->vehicle_plate ?: ($m->asset_id ? optional(\App\Models\Asset::withTrashed()->find($m->asset_id))->registration_number : '');
            WorkflowMessenger::dispatch('external_stock', $event, $m, $actor, [
                'account_id' => $m->account_id ?? $actor?->account_id ?? 1,
                'title' => __('External Stock :r', ['r' => $m->reference]), 'message' => $message ?: __(':k of :p at :w', ['k' => $m->kindLabel(), 'p' => optional($m->contact)->name, 'w' => optional($m->warehouse)->name]),
                'url' => route('external-stock.show', $m->id), 'owner_user_id' => (int) $m->user_id, 'warehouse_ids' => array_filter([$m->warehouse_id]),
                'values' => ['reference' => $m->reference, 'kind' => strtolower($m->kindLabel()), 'person' => optional($m->contact)->name ?? '', 'warehouse' => optional($m->warehouse)->name ?? '', 'collector' => $m->collector_name ?? '', 'mobile' => $m->collector_phone ?? '',
                    'driver' => (string) $driver, 'vehicle' => (string) $plate, 'message' => $message],
                'details' => array_filter([__('Reference') => $m->reference, __('Kind') => $m->kindLabel(), __('Person') => optional($m->contact)->name, __('Warehouse') => optional($m->warehouse)->name, __('Collected by') => $m->collector_name, __('Mobile') => $m->collector_phone, __('Driver') => (string) $driver, __('Vehicle') => (string) $plate]),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('External Stock alert failed', ['movement' => $m->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
