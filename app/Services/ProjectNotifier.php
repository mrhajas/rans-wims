<?php

namespace App\Services;

use App\Models\Warehouse;
use App\Services\Workflow\WorkflowMessenger;

/** Project / warehouse alerts. Who is told, on which channels and with which words is set on the Projects workflow page (warehouses/workflow). */
class ProjectNotifier
{
    public static function fire(string $event, Warehouse $project, string $title, string $message, array $details = []): void
    {
        $actor = auth()->user();
        try {
            $url = route('warehouses.edit', $project->id);
        } catch (\Throwable) {
            $url = null;
        }

        WorkflowMessenger::dispatch('projects', $event, $project, $actor, [
            'account_id' => (int) ($actor?->account_id ?? $project->account_id ?? 1), 'title' => $title, 'message' => $message, 'url' => $url,
            'warehouse_ids' => [$project->id], 'dedupe' => $project->id . '|' . $event . '|' . intdiv(time(), 20),
            'details' => array_filter([__('Project') => $project->name, __('Done by') => $actor?->name] + $details, fn ($v) => filled($v)),
            'values' => ['project' => (string) $project->name],
        ]);
    }
}
