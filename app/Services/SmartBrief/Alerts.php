<?php

namespace App\Services\SmartBrief;

use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/** Sends the Smart Brief events to the Alerts page's audience (every event starts off, so nothing is sent until a Super Admin switches it on). */
class Alerts
{
    public static function fire(string $event, User $viewer, ?User $subject, string $title, string $message, array $values = [], ?string $url = null): void
    {
        WorkflowMessenger::dispatch('smart_brief', $event, null, $viewer, array_filter([
            'account_id' => $viewer->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => $url,
            'owner_user_id' => $subject?->id, 'values' => ['viewer' => $viewer->name] + $values,
            'dedupe' => 'smart_brief|' . $event . '|' . $viewer->id . '|' . ($subject?->id ?? 0) . '|' . intdiv(time(), 1800),
        ], fn ($v) => $v !== null));
    }
}
