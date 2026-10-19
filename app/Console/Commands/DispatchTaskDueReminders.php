<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\TaskDueReminder;
use App\Models\TaskNotificationRule;
use App\Services\TaskWorkflowNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Every task with a due_date reminds its people once the reminder's trigger time arrives. Who is reminded, on which channels and with
 * which words is set on the Task Manager workflow page (events "Task Due Reminder" and the Super Admin alerts); only the timing (the
 * number of hours before the due time) is still the "Remind" setting on Task Manager > Settings.
 *
 * TaskDueReminder's mere existence for (task, user) is the "already sent" guard, so this can safely run every minute without
 * re-notifying anyone; TaskManagerController clears it when a task's due_date/due_time changes.
 */
class DispatchTaskDueReminders extends Command
{
    protected $signature = 'task-manager:dispatch-due-reminders';

    protected $description = 'Remind Task Manager users as a task\'s due date/time approaches or arrives.';

    public function handle(): int
    {
        $tasks = Task::whereNotNull('due_date')
            ->where('status', '!=', 'done')
            ->with(['assignees.assignee', 'createdBy', 'warehouse'])
            ->get();

        $notified = 0;

        foreach ($tasks as $task) {
            try {
                $notified += $this->processTask($task);
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch task due reminder', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Dispatched {$notified} task due reminder(s).");

        return 0;
    }

    protected function processTask(Task $task): int
    {
        $dueAt = $this->dueAt($task);

        if (! $dueAt) {
            return 0;
        }

        $accountId = TaskWorkflowNotifier::accountId($task);
        $rule = TaskNotificationRule::ruleFor($accountId, TaskNotificationRule::EVENT_DUE_REMINDER);
        $triggerAt = $dueAt->copy()->subHours((int) ($rule['reminder_offset_hours'] ?? 0));

        if (now()->lt($triggerAt)) {
            return 0;
        }

        $people = TaskWorkflowNotifier::reminderPeople($task);

        if ($people->isEmpty()) {
            return 0;
        }

        $alreadyNotifiedIds = TaskDueReminder::where('task_id', $task->id)->pluck('user_id');
        $pending = $people->reject(fn ($u) => $alreadyNotifiedIds->contains($u->id))->values();

        if ($pending->isEmpty()) {
            return 0;
        }

        $offsetHours = (int) ($rule['reminder_offset_hours'] ?? 0);
        $dueLabel = $task->due_time
            ? $dueAt->format('M j, Y \a\t g:i A')
            : $dueAt->format('M j, Y');
        $whenPhrase = $offsetHours > 0 ? __('is due in :hours hours', ['hours' => $offsetHours]) : __('is due now');

        TaskWorkflowNotifier::fire('due_reminder', $task, null, __('Task Due Reminder'), "\"{$task->title}\" {$whenPhrase} ({$dueLabel}).", [
            'only' => $pending,
            'values' => ['when' => $whenPhrase],
            'smsline' => "WIMS: \"{$task->title}\" {$whenPhrase} ({$dueLabel}).",
            'dedupe' => 'due' . $task->id . '|' . $dueAt->format('YmdHi'),
        ]);

        foreach ($pending as $user) {
            TaskDueReminder::create(['task_id' => $task->id, 'user_id' => $user->id]);
        }

        return $pending->count();
    }

    protected function dueAt(Task $task): ?Carbon
    {
        if (! $task->due_date) {
            return null;
        }

        $time = $task->due_time ? substr($task->due_time, 0, 5) : '00:00';

        return Carbon::parse($task->due_date->format('Y-m-d') . ' ' . $time);
    }
}
