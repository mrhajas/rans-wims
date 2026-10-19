<?php

namespace App\Services\Weather;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Sends the Weather & Site Risk events to the audience chosen on the module's Alerts page (Recipients supplies the SMS / email / WhatsApp
 * numbers). Text sent by SMS never holds a link: the bell notice carries the address. Events that name a project keep the notice inside that
 * project's people.
 */
class WeatherAlerts
{
    public static function fire(string $event, ?Warehouse $project, string $title, string $message, array $values = [], ?string $url = null, ?User $actor = null, ?string $dedupe = null, int $accountId = 1): void
    {
        WorkflowMessenger::dispatch('weather_risk', $event, null, $actor, array_filter([
            'account_id' => $project?->account_id ?? $actor?->account_id ?? $accountId, 'title' => $title, 'message' => $message, 'url' => $url,
            'warehouse_ids' => $project ? [$project->id] : null,
            'values' => ['project' => $project?->name ?? ''] + $values,
            'dedupe' => $dedupe ?? ('weather_risk|' . $event . '|' . ($project?->id ?? 0) . '|' . intdiv(time(), 600)),
        ], fn ($v) => $v !== null));
    }
}
