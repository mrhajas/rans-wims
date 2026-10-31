<?php

namespace App\Services\MrReminders;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestReminder;
use App\Models\MaterialRequestReminderDelivery;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\FeatureManager;
use App\Services\HolidayGreetings\GreetingSender;
use App\Services\HolidayGreetings\GreetingTemplates;
use App\Services\WimsConnect\GatewayManager;
use App\Services\WimsConnect\OutboundLog;
use App\Services\WimsConnect\RecipientDirectory;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends a Material Request reminder: one row for the reminder (note and files stay with the request) and one delivery row per person and channel, so the report can say who was told
 * what. Nothing here stops at the first failure: every person and channel is tried and written down.
 */
class ReminderSender
{
    public const MAX_FILES = 5;

    public const MAX_KB = 5120;

    /** Waiting for approval, or approved but not yet at the final status (Cleared / Closed): Pending Approval, Approved, Partially Issued and Fully Issued. */
    public static function eligibleStatuses(): array
    {
        return [MaterialRequest::STATUS_PENDING_APPROVAL, MaterialRequest::STATUS_APPROVED, MaterialRequest::STATUS_PARTIALLY_ISSUED, MaterialRequest::STATUS_ISSUED];
    }

    /** A Pending Approval request is chased with the people who can approve it (a Quick request: the quick approvers), not the usual list of recipients. */
    public static function isApprovalChase(MaterialRequest $mr): bool
    {
        return $mr->status === MaterialRequest::STATUS_PENDING_APPROVAL;
    }

    public static function eligible(MaterialRequest $mr): bool
    {
        return in_array($mr->status, self::eligibleStatuses(), true) && ! $mr->isFinal();
    }

    /** Whether the user may send a reminder on this request right now. */
    public static function canSend(User $user, MaterialRequest $mr): bool
    {
        return self::eligible($mr) && $user->can('send-mr-reminders') && FeatureManager::canAccess('mr_reminders', $user);
    }

    /** The people a reminder would reach: the chosen roles and users. The sender is one of them too, unless they chose to leave themselves out. */
    public static function audience(int $accountId, ?User $by = null, bool $leaveOut = false, ?MaterialRequest $mr = null)
    {
        $people = $mr && self::isApprovalChase($mr)
            ? ($mr->quick
                ? \App\Models\MaterialRequestWorkflowStep::usersWithPermission($accountId, \App\Models\MaterialRequestWorkflowStep::PERMISSION_QUICK_APPROVE, true)
                : \App\Models\MaterialRequestWorkflowStep::assignedUsers($accountId, \App\Models\MaterialRequestWorkflowStep::STEP_APPROVE))
            : ReminderSettings::recipients($accountId);

        return $people->reject(fn (User $u) => $leaveOut && $by && $u->id === $by->id)->values();
    }

    /** @param  array<int,\Illuminate\Http\UploadedFile>  $files  @return array{reminder:MaterialRequestReminder,counts:array} */
    public static function send(MaterialRequest $mr, User $by, ?string $note, array $files = [], bool $leaveOut = false): array
    {
        $mr->loadMissing(['warehouse:id,name', 'user:id,name', 'items']);
        $reminder = MaterialRequestReminder::create(['account_id' => $mr->account_id ?: $by->account_id, 'material_request_id' => $mr->id, 'reference' => $mr->reference, 'status_at_send' => (string) $mr->status,
            'note' => filled($note) ? trim($note) : null, 'created_by_user_id' => $by->id]);
        $mail = [];
        foreach ($files as $f) {
            $att = $reminder->attach($f, ['title' => $f->getClientOriginalName(), 'disk' => config('attachments.disk', 'local')]);
            if ($att && $att->filepath) {
                $mail[] = ['path' => Storage::disk($att->disk ?: 'local')->path($att->filepath), 'name' => $att->title ?: basename($att->filepath)];
            }
        }

        $project = optional($mr->warehouse)->name ?? '-';
        $status = MaterialRequest::statusLabel($mr->status);
        $url = route('material-requests.show', $mr->id);
        $title = __('Reminder: Material Request :r is :s', ['r' => $mr->reference, 'd' => $project, 's' => $status]);
        $details = array_filter([
            __('Reference') => $mr->reference, __('Project / Warehouse / Site') => $project, __('Status') => $status, __('Requested by') => optional($mr->user)->name,
            __('Date requested') => optional($mr->date_requested)->format('d M Y'), __('Items') => $mr->items->count(), __('Reminder from') => $by->name,
            __('Notes & Remarks') => $reminder->note, __('Files') => count($mail) ? implode(', ', array_column($mail, 'name')) : null,
        ], fn ($v) => filled($v));
        $chase = self::isApprovalChase($mr);
        $message = ($chase
            ? __(':by reminds you that Material Request :r (:p) is waiting for your approval.', ['by' => $by->name, 'r' => $mr->reference, 'p' => $project])
            : __(':by reminds you that Material Request :r (:p) was approved but has not reached its final status. It is :s.', ['by' => $by->name, 'r' => $mr->reference, 'p' => $project, 's' => $status]))
            . ($reminder->note ? "\n\n" . __('Notes & Remarks') . ': ' . $reminder->note : '');
        $sms = GreetingTemplates::plain(($chase
            ? __('WIMS REMINDER: Material Request :r (:p) is waiting for approval. From :by.', ['r' => $mr->reference, 'p' => $project, 'by' => $by->name])
            : __('WIMS REMINDER: Material Request :r (:p) is :s and not yet cleared. From :by.', ['r' => $mr->reference, 'p' => $project, 's' => $status, 'by' => $by->name]))
            . ($reminder->note ? ' ' . Str::limit($reminder->note, 90, '...') : ''));

        foreach (self::audience((int) $reminder->account_id, $by, $leaveOut, $mr) as $u) {
            $c = ReminderSettings::$addresses ?? RecipientDirectory::candidates($u);
            if (ReminderSettings::app()) {
                self::try($reminder, $u, 'app', null, fn () => $u->notifyNow(new AppNotification(['module' => 'mr_reminders', 'type' => 'mr_reminder', 'title' => $title, 'message' => $message, 'url' => $url, 'status' => $mr->status,
                    'reference' => $mr->reference, 'created_at' => now()->toDateTimeString()])));
            }
            if (ReminderSettings::email()) {
                self::try($reminder, $u, 'email', $c['email'] ?? null, fn () => self::mailTo((string) $c['email'], $u->name)->notifyNow(new AppNotification(['module' => 'mr_reminders', 'type' => 'mr_reminder_mail', 'title' => $title, 'message' => $message, 'url' => $url,
                    'status' => $mr->status, 'reference' => $mr->reference, 'details' => $details, 'send_email' => true, 'email_only' => true, 'mail_attachments' => $mail, 'created_at' => now()->toDateTimeString()])), 'No email address');
            }
            if (ReminderSettings::sms()) {
                self::try($reminder, $u, 'sms', $c['sms'] ?? null, fn () => self::sendSms($reminder, $u, $c['sms'] ?? '', $sms), 'No mobile number');
            }
        }

        log_activity('Material request reminder sent', ['reference' => $mr->reference, 'status' => $status, 'note' => $reminder->note, 'files' => array_column($mail, 'name'), 'people' => $reminder->deliveries()->pluck('name')->unique()->count(),
            'sent' => $reminder->deliveries()->where('status', 'Sent')->count(), 'failed' => $reminder->deliveries()->where('status', 'Failed')->count()], $mr, 'MR Reminders');

        return ['reminder' => $reminder, 'counts' => self::counts($reminder)];
    }

    /** Runs one delivery and writes how it went. A missing address is written down as Skipped, not silently dropped. */
    private static function try(MaterialRequestReminder $r, User $u, string $channel, ?string $address, \Closure $do, string $missing = ''): void
    {
        $row = ['reminder_id' => $r->id, 'user_id' => $u->id, 'name' => $u->name, 'channel' => $channel, 'address' => $address];
        if ($channel !== 'app' && blank($address)) {
            MaterialRequestReminderDelivery::create($row + ['status' => 'Skipped', 'error' => __($missing)]);

            return;
        }
        try {
            $do();
            MaterialRequestReminderDelivery::create($row + ['status' => 'Sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
            MaterialRequestReminderDelivery::create($row + ['status' => 'Failed', 'error' => Str::limit(strip_tags($e->getMessage()), 300) ?: __('It could not be sent.')]);
        }
    }

    /**
     * Somebody to send the email to by address. A reminder is a management message, so it goes to the address WIMS holds for the person (the same one the SMS number comes with) and does not
     * wait for the person to have switched their own email channel on; and it never depends on a login record, so a sample run can send to one test address.
     */
    private static function mailTo(string $email, string $name)
    {
        return new class($email, $name) {
            use \Illuminate\Notifications\Notifiable;

            public function __construct(public string $email, public string $name)
            {
            }

            public function routeNotificationForMail($notification = null): string
            {
                return $this->email;
            }
        };
    }

    private static function sendSms(MaterialRequestReminder $r, User $u, string $to, string $text): void
    {
        if (! GreetingSender::smsReady()) {
            throw new \RuntimeException(__('SMS is switched off (WIMS Connect or its SMS channel).'));
        }
        $result = app(GatewayManager::class)->send('sms', $to, new OutboundMessage($text));
        try {
            OutboundLog::record(['event_key' => 'material-request-reminder', 'channel' => 'sms', 'address' => $to, 'message' => $text, 'source_type' => MaterialRequestReminder::class, 'source_id' => $r->id,
                'recipient_user_id' => $u->id, 'status' => $result->success ? 'SENT' : 'FAILED', 'sent_at' => $result->success ? now() : null, 'error_message' => $result->success ? null : Str::limit(strip_tags((string) $result->error), 300)]);
        } catch (\Throwable $e) {
            report($e);
        }
        if (! $result->success) {
            throw new \RuntimeException($result->error ?: __('The SMS gateway refused the message.'));
        }
    }

    /** @return array{email:array,sms:array,app:array,people:int} sent / total per channel */
    public static function counts(MaterialRequestReminder $r): array
    {
        $rows = $r->deliveries()->get(['user_id', 'channel', 'status']);
        $o = ['people' => $rows->pluck('user_id')->unique()->count()];
        foreach (['email', 'sms', 'app'] as $ch) {
            $g = $rows->where('channel', $ch);
            $o[$ch] = ['sent' => $g->where('status', 'Sent')->count(), 'failed' => $g->where('status', 'Failed')->count(), 'skipped' => $g->where('status', 'Skipped')->count(), 'total' => $g->count()];
        }

        return $o;
    }

    /** One reminder as the pages show it. */
    public static function present(MaterialRequestReminder $r, bool $withPeople = true): array
    {
        $r->loadMissing(['sender:id,name', 'attachments']);

        return [
            'id' => $r->id, 'at' => optional($r->created_at)->toIso8601String(), 'by' => optional($r->sender)->name, 'note' => $r->note, 'status' => MaterialRequest::statusLabel($r->status_at_send), 'reference' => $r->reference,
            'files' => $r->attachments->map(fn ($a) => ['id' => $a->id, 'title' => $a->title])->values(),
            'counts' => self::counts($r),
            'people' => $withPeople ? $r->deliveries()->orderBy('name')->orderBy('channel')->get()->map(fn ($d) => ['name' => $d->name, 'channel' => $d->channel, 'address' => $d->address, 'status' => $d->status, 'error' => $d->error])->values() : [],
        ];
    }
}
