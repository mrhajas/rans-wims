<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Asset alerts. Who is told, on which channels and with which words is set on the Assets workflow page (company-assets/workflow);
 * this class says which event happened.
 */
class AssetNotifier
{
    public static function fire(string $event, Asset $asset, string $title, string $message, array $values = [], array $ctx = []): void
    {
        $actor = auth()->user();
        $assigned = $asset->assigned_to_user_id ? User::whereKey($asset->assigned_to_user_id)->first(['id', 'name', 'account_id']) : null;
        $accountId = (int) ($asset->account_id ?? $assigned?->account_id ?? $actor?->account_id ?? 1);

        WorkflowMessenger::dispatch('assets', $event, $asset, $actor, $ctx + [
            'account_id' => $accountId, 'title' => $title, 'message' => $message,
            'url' => route('assets.show', $asset->id),
            'owner_user_id' => (int) $asset->assigned_to_user_id,
            'warehouse_ids' => $asset->current_warehouse_id ? [$asset->current_warehouse_id] : null,
            'dedupe' => $asset->id . '|' . $event . '|' . intdiv(time(), 20),
            'values' => $values + [
                'asset' => (string) $asset->name, 'assetnumber' => (string) $asset->asset_number, 'assignedto' => (string) optional($assigned)->name,
            ],
        ]);
    }

    /** Where the asset is now, in words: the project / warehouse, or the location typed on it. */
    public static function place(Asset $asset): string
    {
        $asset->unsetRelation('currentWarehouse');

        return (string) (optional($asset->currentWarehouse)->name ?: $asset->current_location_label ?: '');
    }

    /** A change on the Asset Categories screen (the category is the record; no single owner). */
    public static function fireCategory(string $event, \App\Models\AssetCategory $category, string $title, string $message): void
    {
        $actor = auth()->user();
        WorkflowMessenger::dispatch('assets', $event, $category, $actor, [
            'account_id' => (int) ($actor?->account_id ?? 1), 'title' => $title, 'message' => $message,
            'url' => route('assets.categories.index'),
            'dedupe' => 'cat|' . $category->id . '|' . $event . '|' . intdiv(time(), 20),
            'values' => ['category' => (string) $category->name],
        ]);
    }
}
