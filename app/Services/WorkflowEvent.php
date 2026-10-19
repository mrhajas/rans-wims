<?php

namespace App\Services;

use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;
use Illuminate\Database\Eloquent\Model;

/**
 * One call for the "something was done to a record" notices of the modules that have no notifier class of their own (Labour Lists,
 * Project Certificates, Salary). Who is told, on which channels and with which words is set on the module's workflow page. Never throws.
 * The person who did the action is not told about it (skip_actor) unless $ctx says otherwise; the dedupe key includes the message so
 * two different actions on the same record in a row are both sent.
 */
class WorkflowEvent
{
    public static function fire(string $module, string $event, ?Model $record, ?User $actor, string $title, string $message, array $ctx = []): bool
    {
        try {
            $actor ??= auth()->user();

            return WorkflowMessenger::dispatch($module, $event, $record, $actor, $ctx + [
                'account_id' => $actor?->account_id ?? 1, 'skip_actor' => true,
                'title' => $title, 'message' => $message,
                'dedupe' => $event . '|' . ($record?->getKey() ?? 0) . '|' . md5($message) . '|' . intdiv(time(), 20),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Workflow notice failed', ['module' => $module, 'event' => $event, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
