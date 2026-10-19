<?php

namespace App\Notifications;

use App\Models\TaskReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TaskReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected TaskReminder $reminder)
    {
    }

    public function via($notifiable)
    {
        return $notifiable->task_reminders_email_enabled ? ['database', 'mail'] : ['database'];
    }

    public function toArray($notifiable)
    {
        return [
            'module' => 'task_reminders',
            'title' => $this->reminder->name,
            'message' => $this->reminder->notes ? Str::limit($this->reminder->notes, 100) : __('Your reminder is due now.'),
            'url' => route('task-reminders.index', ['highlight' => $this->reminder->id]),
            'task_reminder_id' => $this->reminder->id,
        ];
    }

    public function toMail($notifiable)
    {
        $priorityColors = [
            'low' => ['bg' => '#f1f5f9', 'text' => '#475569'],
            'normal' => ['bg' => '#dbeafe', 'text' => '#1d4ed8'],
            'high' => ['bg' => '#fef3c7', 'text' => '#b45309'],
            'urgent' => ['bg' => '#fee2e2', 'text' => '#b91c1c'],
        ];

        return (new MailMessage)
            ->subject(__(':name, reminder due', ['name' => $this->reminder->name]))
            ->view('emails.task-reminder', [
                'reminder' => $this->reminder,
                'notifiable' => $notifiable,
                'url' => route('task-reminders.index', ['highlight' => $this->reminder->id]),
                'priorityColors' => $priorityColors,
            ]);
    }
}
