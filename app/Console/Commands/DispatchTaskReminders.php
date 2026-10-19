<?php

namespace App\Console\Commands;

use App\Models\TaskReminder;
use App\Services\TaskWorkflowNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchTaskReminders extends Command
{
    protected $signature = 'task-reminders:dispatch';

    protected $description = 'Notify users whose task reminders are now due (set on the Task Manager workflow page: in-app, and by email if they opted in).';

    public function handle(): int
    {
        $due = TaskReminder::withoutGlobalScopes(['account', 'mine'])
            ->with('user')
            ->whereNull('notified_at')
            ->where('is_completed', false)
            ->where('due_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->get();

        $notified = 0;

        foreach ($due as $reminder) {
            try {
                if (! $reminder->user) {
                    continue;
                }

                TaskWorkflowNotifier::personalReminder($reminder);
                $reminder->forceFill(['notified_at' => now()])->saveQuietly();
                $notified++;
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch task reminder', [
                    'task_reminder_id' => $reminder->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Dispatched {$notified} task reminder(s).");

        return 0;
    }
}
