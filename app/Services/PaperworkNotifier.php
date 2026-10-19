<?php

namespace App\Services;

use App\Models\Checkin;
use App\Models\Transfer;
use App\Services\Workflow\WorkflowMessenger;
use Illuminate\Database\Eloquent\Model;

/**
 * Tells the Check-ins / Transfers workflow page that a Delivery Note or a Gate Pass was generated from one of their records
 * (events "delivery_note" and "gate_pass"). Who is told, on which channels and with which words is set on that page.
 */
class PaperworkNotifier
{
    /** $kind: 'delivery_note' or 'gate_pass'. */
    public static function fire(string $kind, Model $record, string $documentNumber): void
    {
        try {
            $module = $record instanceof Checkin ? 'checkins' : ($record instanceof Transfer ? 'transfers' : null);
            if (! $module) {
                return;
            }
            $actor = auth()->user();
            $name = $kind === 'gate_pass' ? __('Gate Pass') : __('Delivery Note');
            $record->loadMissing($record instanceof Transfer ? ['fromWarehouse:id,name', 'toWarehouse:id,name', 'user:id,name'] : ['warehouse:id,name', 'user:id,name']);
            $values = ['reference' => (string) $record->reference, 'document' => $documentNumber, 'creator' => (string) optional($record->user)->name];
            if ($record instanceof Transfer) {
                $values += ['from' => (string) optional($record->fromWarehouse)->name, 'to' => (string) optional($record->toWarehouse)->name];
                $warehouses = array_filter([$record->to_warehouse_id]);
                $url = route('transfers.show', $record->id);
            } else {
                $values += ['warehouse' => (string) optional($record->warehouse)->name];
                $warehouses = array_filter([$record->warehouse_id]);
                $url = route('checkins.show', $record->id);
            }

            WorkflowMessenger::dispatch($module, $kind, $record, $actor, [
                'account_id' => $record->account_id ?? $actor?->account_id ?? 1, 'title' => $name . ' ' . __('Generated'),
                'message' => $name . ' ' . $documentNumber . ' was generated from ' . $record->reference . ($actor ? ' by ' . $actor->name : '') . '.',
                'url' => $url, 'owner_user_id' => (int) $record->user_id, 'warehouse_ids' => $warehouses, 'skip_actor' => true,
                'values' => $values, 'dedupe' => $kind . '|' . $record->getKey() . '|' . $documentNumber,
                'details' => [__('Reference') => $record->reference, $name => $documentNumber, __('Done by') => $actor?->name],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PaperworkNotifier failed', ['kind' => $kind, 'error' => $e->getMessage()]);
        }
    }
}
