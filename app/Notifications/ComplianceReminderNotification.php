<?php

namespace App\Notifications;

use App\Models\ComplianceRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A `severity` flag distinguishes a normal reminder from an escalation
 * rather than a second notification class.
 */
class ComplianceReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected ComplianceRecord $record, protected int $daysRemaining, protected string $severity = 'reminder')
    {
    }

    public function via($notifiable)
    {
        return $notifiable->compliance_reminder_email_enabled ? ['database', 'mail'] : ['database'];
    }

    public function toArray($notifiable)
    {
        $isEscalation = $this->severity === 'escalation';

        return [
            'module' => 'compliance_records',
            'type' => 'compliance_reminder',
            'severity' => $this->severity,
            'compliance_record_id' => $this->record->id,
            'title' => $this->title($isEscalation),
            'message' => $this->message(),
            'url' => route('compliance-records.show', $this->record->id, false),
            'created_at' => now()->toDateTimeString(),
        ];
    }

    public function toMail($notifiable)
    {
        $isEscalation = $this->severity === 'escalation';

        return (new MailMessage)
            ->subject($this->title($isEscalation))
            ->view('emails.compliance-reminder', [
                'record' => $this->record,
                'notifiable' => $notifiable,
                'isEscalation' => $isEscalation,
                'message_text' => $this->message(),
                'url' => route('compliance-records.show', $this->record->id),
            ]);
    }

    private function title(bool $isEscalation): string
    {
        return $isEscalation
            ? __('Escalation - :title', ['title' => $this->record->title])
            : __('Compliance renewal required - :title', ['title' => $this->record->title]);
    }

    private function message(): string
    {
        return $this->daysRemaining < 0
            ? __(':title (:number) expired :days day(s) ago. Action required: renew.', [
                'title' => $this->record->title,
                'number' => $this->record->compliance_number,
                'days' => abs($this->daysRemaining),
            ])
            : __(':title (:number) expires in :days day(s). Action required: renew.', [
                'title' => $this->record->title,
                'number' => $this->record->compliance_number,
                'days' => $this->daysRemaining,
            ]);
    }
}
